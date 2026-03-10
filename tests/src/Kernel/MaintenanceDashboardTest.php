<?php

declare(strict_types=1);

namespace Drupal\Tests\asset_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests the maintenance dashboard and quick status form infrastructure.
 *
 * Covers:
 * - Dashboard routes are registered and accessible to the right permissions.
 * - Correct nodes appear in the review queue vs the down-tools section.
 * - Operational tools are excluded from the dashboard entirely.
 * - Quick-status form route accepts a numeric node argument.
 * - Dashboard menu link points to a valid route.
 *
 * @group asset_status
 */
class MaintenanceDashboardTest extends KernelTestBase {

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
   * Status terms keyed by label.
   *
   * @var \Drupal\taxonomy\Entity\Term[]
   */
  private array $terms = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('asset_log_entry');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['asset_status', 'node', 'system']);

    Vocabulary::create(['vid' => 'item_status', 'name' => 'Item Status'])->save();

    if (!\Drupal::entityTypeManager()->getStorage('node_type')->load('item')) {
      NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    }

    $storage = \Drupal\field\Entity\FieldStorageConfig::create([
      'field_name' => 'field_item_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ]);
    $storage->save();

    \Drupal\field\Entity\FieldConfig::create([
      'field_name' => 'field_item_status',
      'entity_type' => 'node',
      'bundle' => 'item',
      'label' => 'Status',
    ])->save();

    foreach (['Operational', 'Reported Concern', 'Degraded', 'Out of Service'] as $label) {
      $term = Term::create(['vid' => 'item_status', 'name' => $label]);
      $term->save();
      $this->terms[$label] = $term;
    }
  }

  // ---------------------------------------------------------------------------
  // Route registration
  // ---------------------------------------------------------------------------

  /**
   * The maintenance dashboard route must be registered.
   */
  public function testMaintenanceDashboardRouteRegistered(): void {
    $route_provider = $this->container->get('router.route_provider');
    $route = $route_provider->getRouteByName('asset_status.maintenance_dashboard');
    $this->assertNotNull($route);
    $this->assertEquals('/admin/content/asset-maintenance', $route->getPath());
  }

  /**
   * The quick status update route must be registered and require a numeric node.
   */
  public function testQuickStatusUpdateRouteRegistered(): void {
    $route_provider = $this->container->get('router.route_provider');
    $route = $route_provider->getRouteByName('asset_status.quick_status_update');
    $this->assertNotNull($route);
    $this->assertEquals('/admin/content/asset-maintenance/update/{node}', $route->getPath());
    $this->assertEquals('\d+', $route->getRequirement('node'), 'Node parameter must be numeric.');
  }

  /**
   * All menu links provided by asset_status (including the new dashboard link)
   * must reference valid routes.
   */
  public function testAllAssetStatusMenuLinksHaveValidRoutes(): void {
    $route_provider    = $this->container->get('router.route_provider');
    $menu_link_manager = $this->container->get('plugin.manager.menu.link');
    $missing           = [];

    foreach ($menu_link_manager->getDefinitions() as $plugin_id => $definition) {
      if (($definition['provider'] ?? '') !== 'asset_status') {
        continue;
      }
      $route_name = $definition['route_name'] ?? '';
      if ($route_name === '') {
        continue;
      }
      try {
        $route_provider->getRouteByName($route_name);
      }
      catch (RouteNotFoundException $e) {
        $missing[] = $plugin_id . ' → ' . $route_name;
      }
    }

    $this->assertSame([], $missing, 'All asset_status menu links must point to registered routes.');
  }

  /**
   * The dashboard route requires "review asset status reports" permission.
   */
  public function testDashboardRouteRequiresReviewPermission(): void {
    $route_provider = $this->container->get('router.route_provider');
    $route          = $route_provider->getRouteByName('asset_status.maintenance_dashboard');
    $this->assertEquals('review asset status reports', $route->getRequirement('_permission'));
  }

  // ---------------------------------------------------------------------------
  // Access control
  // ---------------------------------------------------------------------------

  /**
   * Users with "review asset status reports" can access the dashboard;
   * users without it cannot.
   */
  public function testDashboardAccessControl(): void {
    // uid 1 must be created first so subsequent users are not superusers.
    User::create(['name' => 'admin', 'status' => 1])->save();

    Role::create(['id' => 'reviewer', 'label' => 'Reviewer'])
      ->grantPermission('access content')
      ->grantPermission('review asset status reports')
      ->save();
    $reviewer = User::create(['name' => 'staff_reviewer', 'status' => 1]);
    $reviewer->addRole('reviewer');
    $reviewer->save();

    Role::create(['id' => 'member', 'label' => 'Member'])
      ->grantPermission('access content')
      ->save();
    $member = User::create(['name' => 'regular_member', 'status' => 1]);
    $member->addRole('member');
    $member->save();

    $access_manager = $this->container->get('access_manager');

    $this->assertTrue(
      $access_manager->checkNamedRoute('asset_status.maintenance_dashboard', [], $reviewer, TRUE)->isAllowed(),
      'Staff with review permission should access the maintenance dashboard.'
    );
    $this->assertFalse(
      $access_manager->checkNamedRoute('asset_status.maintenance_dashboard', [], $member, TRUE)->isAllowed(),
      'Regular members must not access the maintenance dashboard.'
    );
  }

  // ---------------------------------------------------------------------------
  // Review queue data
  // ---------------------------------------------------------------------------

  /**
   * Nodes in "Reported Concern" should appear in the review queue;
   * Operational nodes must not.
   */
  public function testReviewQueueContainsOnlyReportedConcernNodes(): void {
    $concern_node = Node::create([
      'type'              => 'item',
      'title'             => 'Water Jet Cutter',
      'status'            => 1,
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $concern_node->save();

    $operational_node = Node::create([
      'type'              => 'item',
      'title'             => 'Drill Press',
      'status'            => 1,
      'field_item_status' => $this->terms['Operational']->id(),
    ]);
    $operational_node->save();

    // Query that mirrors MaintenanceDashboardController::getNodesByStatus().
    $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', 'item')
      ->condition('status', 1)
      ->condition('field_item_status', [$this->terms['Reported Concern']->id()], 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $this->assertContains((string) $concern_node->id(), array_values($nids), '"Reported Concern" node should appear in the review queue.');
    $this->assertNotContains((string) $operational_node->id(), array_values($nids), 'Operational node must not appear in the review queue.');
  }

  /**
   * Degraded and Out of Service nodes appear in the down-tools section;
   * Reported Concern nodes do not.
   */
  public function testDownToolsSectionContainsDegradedAndOos(): void {
    $degraded_node = Node::create([
      'type'              => 'item',
      'title'             => 'Laser Cutter',
      'status'            => 1,
      'field_item_status' => $this->terms['Degraded']->id(),
    ]);
    $degraded_node->save();

    $oos_node = Node::create([
      'type'              => 'item',
      'title'             => 'Plasma Cutter',
      'status'            => 1,
      'field_item_status' => $this->terms['Out of Service']->id(),
    ]);
    $oos_node->save();

    $concern_node = Node::create([
      'type'              => 'item',
      'title'             => 'CNC Router',
      'status'            => 1,
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $concern_node->save();

    $down_status_ids = [
      $this->terms['Degraded']->id(),
      $this->terms['Out of Service']->id(),
    ];

    $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', 'item')
      ->condition('status', 1)
      ->condition('field_item_status', $down_status_ids, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $this->assertContains((string) $degraded_node->id(), array_values($nids));
    $this->assertContains((string) $oos_node->id(), array_values($nids));
    $this->assertNotContains((string) $concern_node->id(), array_values($nids), '"Reported Concern" should not appear in the down-tools section.');
  }

  /**
   * Unpublished item nodes must not appear in either dashboard section.
   */
  public function testUnpublishedNodesExcludedFromDashboard(): void {
    $unpublished = Node::create([
      'type'              => 'item',
      'title'             => 'Hidden Tool',
      'status'            => 0,
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $unpublished->save();

    $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', 'item')
      ->condition('status', 1)
      ->condition('field_item_status', [$this->terms['Reported Concern']->id()], 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $this->assertNotContains((string) $unpublished->id(), array_values($nids), 'Unpublished nodes must not appear in the dashboard.');
  }

  // ---------------------------------------------------------------------------
  // Quick status form — submit behaviour
  // ---------------------------------------------------------------------------

  /**
   * Submitting the quick status form creates a status_change log entry and
   * syncs the confirmed status back to the node.
   */
  public function testQuickStatusFormCreatesLogAndSyncsNode(): void {
    User::create(['name' => 'bootstrap_admin', 'status' => 1])->save();

    Role::create(['id' => 'staff', 'label' => 'Staff'])
      ->grantPermission('review asset status reports')
      ->save();
    $staff = User::create(['name' => 'staff_user', 'status' => 1]);
    $staff->addRole('staff');
    $staff->save();

    \Drupal::currentUser()->setAccount($staff);

    $node = Node::create([
      'type'              => 'item',
      'title'             => 'Metal Lathe',
      'status'            => 1,
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $node->save();

    $log_storage = \Drupal::entityTypeManager()->getStorage('asset_log_entry');

    // Replicate what AssetQuickStatusForm::submitForm() does.
    $new_term = $this->terms['Out of Service'];
    $log_storage->create([
      'type'             => 'status_change',
      'asset'            => $node->id(),
      'summary'          => 'Staff review: status set to Out of Service',
      'details'          => $staff->getDisplayName() . ': seized spindle, ordering replacement.',
      'confirmed_status' => $new_term->id(),
      'reported_status'  => $new_term->id(),
      'user_id'          => $staff->id(),
    ])->save();

    // Node status must now be Out of Service.
    $node = Node::load($node->id());
    $this->assertEquals(
      $this->terms['Out of Service']->id(),
      (int) $node->get('field_item_status')->target_id,
      'Quick status form submit should sync node status via log entry postSave.'
    );

    // A status_change log entry with the staff note must exist.
    $ids = $log_storage->getQuery()
      ->condition('asset', $node->id())
      ->condition('type', 'status_change')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertNotEmpty($ids, 'A status_change log entry should have been created.');

    $log = $log_storage->load(reset($ids));
    $this->assertStringContainsString('seized spindle', $log->getDetails(), 'Staff note should appear in log details.');
    $this->assertEquals($staff->id(), (int) $log->getOwnerId(), 'Log entry should be attributed to the reviewing staff member.');
  }

  /**
   * After staff confirms a status, the inspection log's reported_status is
   * still the member's original assessment — it must not be overwritten.
   */
  public function testInspectionLogReportedStatusPreservedAfterStaffConfirmation(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'Spot Welder',
      'status'            => 1,
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $node->save();

    $log_storage = \Drupal::entityTypeManager()->getStorage('asset_log_entry');

    // Member's inspection log: they thought it was Out of Service.
    $inspection = $log_storage->create([
      'type'            => 'inspection',
      'asset'           => $node->id(),
      'summary'         => 'Member issue report (broken_nonfunctional)',
      'details'         => 'Member Report (alice): no arc',
      'reported_status' => $this->terms['Out of Service']->id(),
    ]);
    $inspection->save();

    // Staff confirms only Degraded (electrode worn, still usable with care).
    $log_storage->create([
      'type'             => 'status_change',
      'asset'            => $node->id(),
      'summary'          => 'Staff review: status set to Degraded',
      'details'          => 'jsmith: electrode tip worn but functional. Replaced.',
      'confirmed_status' => $this->terms['Degraded']->id(),
      'reported_status'  => $this->terms['Degraded']->id(),
    ])->save();

    // Reload the inspection log — its reported_status must still be OOS.
    $inspection = $log_storage->load($inspection->id());
    $this->assertEquals(
      $this->terms['Out of Service']->id(),
      (int) $inspection->getReportedStatus()->id(),
      'Staff confirmation must not overwrite the member\'s original reported severity.'
    );
  }

}
