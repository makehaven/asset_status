<?php

namespace Drupal\Tests\asset_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests asset status logging and availability logic.
 *
 * @group asset_status
 */
class AssetStatusTest extends KernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('asset_log_entry');
    $this->installConfig(['asset_status', 'node']);

    // Create the item_status vocabulary.
    Vocabulary::create(['vid' => 'item_status', 'name' => 'Item Status'])->save();

    // Create the item content type and status field.
    // In a real kernel test, we might mock this or rely on config installation,
    // but creating it programmatically ensures the test is self-contained.
    $this->createItemContentType();
  }

  /**
   * Tests the availability service logic.
   */
  public function testAvailabilityService(): void {
    $container = \Drupal::getContainer();
    $service = $container->get('asset_status.availability');
    $this->assertNotNull($service);

    $operational = Term::create(['vid' => 'item_status', 'name' => 'Operational']);
    $operational->save();

    $broken = Term::create(['vid' => 'item_status', 'name' => 'Out of Service']);
    $broken->save();

    $this->assertTrue($service->isUsable($operational));
    $this->assertFalse($service->isUsable($broken));
  }

  /**
   * Tests that changing a node status logs a record.
   */
  public function testStatusChangeLogging(): void {
    $term_up = Term::create(['vid' => 'item_status', 'name' => 'Up']);
    $term_up->save();

    $term_down = Term::create(['vid' => 'item_status', 'name' => 'Down']);
    $term_down->save();

    // 1. Create a node with initial status.
    $node = Node::create([
      'type' => 'item',
      'title' => 'Laser Cutter',
      'field_item_status' => $term_up->id(),
    ]);
    $node->save();

    // Verify initial creation log.
    $logs = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->loadMultiple();
    $this->assertCount(1, $logs);
    $first_log = reset($logs);
    $this->assertEquals('status_change', $first_log->bundle());
    $this->assertEquals($term_up->id(), $first_log->getConfirmedStatus()->id());

    // 2. Update status.
    $node->set('field_item_status', $term_down->id());
    $node->save();

    // Verify second log.
    $logs = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->loadMultiple();
    $this->assertCount(2, $logs);
    
    // Check the latest log.
    $latest_log = end($logs);
    $this->assertEquals($term_down->id(), $latest_log->getConfirmedStatus()->id());
    $this->assertStringContainsString('Status changed from Up to Down', $latest_log->getSummary());
  }

  /**
   * Helper to create the item content type and field.
   */
  private function createItemContentType(): void {
    // Create 'item' content type.
    if (!\Drupal::entityTypeManager()->getStorage('node_type')->load('item')) {
      \Drupal\node\Entity\NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    }

    // Add field_item_status.
    $field_storage = \Drupal\field\Entity\FieldStorageConfig::create([
      'field_name' => 'field_item_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ]);
    $field_storage->save();

    $field = \Drupal\field\Entity\FieldConfig::create([
      'field_name' => 'field_item_status',
      'entity_type' => 'node',
      'bundle' => 'item',
      'label' => 'Status',
    ]);
    $field->save();
  }

}
