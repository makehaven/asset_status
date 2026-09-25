<?php

declare(strict_types=1);

namespace Drupal\Tests\asset_status\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Multi-unit tools: units, parents, roll-up and retirement.
 *
 * @group asset_status
 */
class UnitManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'asset_status',
    'node',
    'system',
    'user',
    'taxonomy',
    'text',
    'field',
    'datetime',
    'options',
    'workflows',
    'file',
    'image',
  ];

  /**
   * Status terms keyed by label.
   *
   * @var \Drupal\taxonomy\Entity\Term[]
   */
  private array $status = [];

  /**
   * The "Machine Unit" category term.
   */
  private Term $unitTerm;

  /**
   * The "Stationary Equipment" category term.
   */
  private Term $equipmentTerm;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installEntitySchema('asset_log_entry');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['asset_status', 'node', 'system']);

    Vocabulary::create(['vid' => 'item_status', 'name' => 'Item Status'])->save();
    Vocabulary::create(['vid' => 'item_category', 'name' => 'Item Category'])->save();
    NodeType::create(['type' => 'item', 'name' => 'Item'])->save();

    foreach (['field_item_status' => 'item_status', 'field_item_category' => 'item_category'] as $field => $vid) {
      FieldStorageConfig::create([
        'field_name' => $field,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => $field,
        'entity_type' => 'node',
        'bundle' => 'item',
        'settings' => ['handler_settings' => ['target_bundles' => [$vid => $vid]]],
      ])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_item_set',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_item_set',
      'entity_type' => 'node',
      'bundle' => 'item',
      'settings' => ['handler_settings' => ['target_bundles' => ['item' => 'item']]],
    ])->save();

    foreach (['Operational', 'Reported Concern', 'Degraded', 'Offline for Maintenance', 'Gone'] as $label) {
      $term = Term::create(['vid' => 'item_status', 'name' => $label]);
      $term->save();
      $this->status[$label] = $term;
    }
    $this->unitTerm = Term::create(['vid' => 'item_category', 'name' => 'Machine Unit']);
    $this->unitTerm->save();
    $this->equipmentTerm = Term::create(['vid' => 'item_category', 'name' => 'Stationary Equipment']);
    $this->equipmentTerm->save();
  }

  /**
   * Creates an item node.
   */
  private function item(string $title, string $status, Term $category, array $children = []): Node {
    $node = Node::create([
      'type' => 'item',
      'title' => $title,
      'status' => 1,
      'field_item_status' => $this->status[$status]->id(),
      'field_item_category' => $category->id(),
      'field_item_set' => array_map(fn(Node $n) => $n->id(), $children),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Builds the laser: one tool node, two machines.
   *
   * @return \Drupal\node\Entity\Node[]
   *   [parent, laser1, laser2].
   */
  private function laser(string $status1 = 'Operational', string $status2 = 'Operational'): array {
    $laser1 = $this->item('Laser 1', $status1, $this->unitTerm);
    $laser2 = $this->item('Laser 2', $status2, $this->unitTerm);
    $parent = $this->item('32x18 ULS Laser Cutter', 'Operational', $this->equipmentTerm, [$laser1, $laser2]);
    return [$parent, $laser1, $laser2];
  }

  /**
   * A plain tool is not a unit and has no units; a unit knows its parent.
   */
  public function testRecognition(): void {
    /** @var \Drupal\asset_status\Service\UnitManager $units */
    $units = $this->container->get('asset_status.units');
    $saw = $this->item('Table Saw', 'Operational', $this->equipmentTerm);
    $this->assertFalse($units->isUnit($saw));
    $this->assertFalse($units->hasUnits($saw));
    $this->assertSame('Table Saw', $units->displayTitle($saw));

    [$parent, $laser1, $laser2] = $this->laser();
    $this->assertFalse($units->isUnit($parent));
    $this->assertTrue($units->hasUnits($parent));
    $this->assertTrue($units->isUnit($laser1));
    $this->assertSame($parent->id(), $units->getParent($laser2)->id());
    $this->assertSame('Laser 2 (32x18 ULS Laser Cutter)', $units->displayTitle($laser2));
    $this->assertSame([(int) $laser1->id() => (int) $parent->id(), (int) $laser2->id() => (int) $parent->id()],
      $units->parentMap([(int) $saw->id(), (int) $laser1->id(), (int) $laser2->id(), (int) $parent->id()]));
  }

  /**
   * The roll-up counts usable machines and leaves retired ones out.
   */
  public function testSummary(): void {
    /** @var \Drupal\asset_status\Service\UnitManager $units */
    $units = $this->container->get('asset_status.units');

    [$parent] = $this->laser('Operational', 'Operational');
    $s = $units->summary($parent);
    $this->assertSame(2, $s['total']);
    $this->assertSame(2, $s['available']);

    [$parent] = $this->laser('Operational', 'Offline for Maintenance');
    $s = $units->summary($parent);
    $this->assertSame(2, $s['total']);
    $this->assertSame(1, $s['available']);
    $down = array_values(array_filter($s['units'], fn($u) => !$u['usable']));
    $this->assertSame('Laser 2', $down[0]['title']);
    $this->assertSame('Offline for Maintenance', $down[0]['status']);
    $this->assertNotNull($down[0]['since'], 'creation logged a status_change entry the roll-up reads "since" from');

    // Reported Concern is still usable (not confirmed), Degraded too.
    [$parent] = $this->laser('Reported Concern', 'Degraded');
    $this->assertSame(2, $units->summary($parent)['available']);

    // A retired machine is not part of the fleet.
    [$parent] = $this->laser('Gone', 'Operational');
    $s = $units->summary($parent);
    $this->assertSame(1, $s['total']);
    $this->assertSame(1, $s['available']);
    $this->assertCount(2, $units->getUnits($parent, TRUE));
  }

  /**
   * Retiring the last machine retires the tool; a machine coming back revives it.
   */
  public function testParentFollowsRetirement(): void {
    /** @var \Drupal\asset_status\Service\UnitManager $units */
    $units = $this->container->get('asset_status.units');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');

    [$parent, $laser1, $laser2] = $this->laser();

    $laser1->set('field_item_status', $this->status['Gone']->id())->save();
    $parent = $storage->loadUnchanged($parent->id());
    $this->assertSame('Operational', $units->statusLabel($parent), 'one machine left: tool stays');

    $laser2->set('field_item_status', $this->status['Gone']->id())->save();
    $parent = $storage->loadUnchanged($parent->id());
    $this->assertSame('Gone', $units->statusLabel($parent), 'last machine retired: tool retired');
    $entries = $this->container->get('entity_type.manager')->getStorage('asset_log_entry')->loadByProperties(['asset' => $parent->id()]);
    $summaries = array_map(fn($e) => $e->getDetails() ?: $e->getSummary(), $entries);
    $this->assertNotEmpty(array_filter($summaries, fn($t) => str_contains((string) $t, 'All 2 units retired')));

    $laser2 = $storage->loadUnchanged($laser2->id());
    $laser2->set('field_item_status', $this->status['Operational']->id())->save();
    $parent = $storage->loadUnchanged($parent->id());
    $this->assertSame('Operational', $units->statusLabel($parent), 'a machine back in the fleet revives the tool');

    // A unit status change that is not a retirement leaves the tool alone.
    $laser2->set('field_item_status', $this->status['Offline for Maintenance']->id())->save();
    $parent = $storage->loadUnchanged($parent->id());
    $this->assertSame('Operational', $units->statusLabel($parent));
  }

  /**
   * Without the "Machine Unit" term nothing is a unit and nothing changes.
   */
  public function testNoTermMeansNoUnits(): void {
    $this->unitTerm->delete();
    /** @var \Drupal\asset_status\Service\UnitManager $units */
    $units = $this->container->get('asset_status.units');
    $laser1 = $this->item('Laser 1', 'Operational', $this->equipmentTerm);
    $parent = $this->item('Laser', 'Operational', $this->equipmentTerm, [$laser1]);
    $this->assertNull($units->unitTermId());
    $this->assertFalse($units->hasUnits($parent));
    $this->assertSame([], $units->parentMap([(int) $laser1->id()]));
  }

}
