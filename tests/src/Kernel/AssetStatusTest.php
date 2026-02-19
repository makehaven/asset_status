<?php

namespace Drupal\Tests\asset_status\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\asset_status\Controller\AssetLogController;
use Drupal\asset_status\Entity\AssetLogEntryListBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

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
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['asset_status', 'node', 'system']);

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
   * Tests that manual log entries can sync status back to the node.
   */
  public function testManualLogSync(): void {
    $term_broken = Term::create(['vid' => 'item_status', 'name' => 'Broken']);
    $term_broken->save();

    $term_fixed = Term::create(['vid' => 'item_status', 'name' => 'Fixed']);
    $term_fixed->save();

    // 1. Create a node that is broken.
    $node = Node::create([
      'type' => 'item',
      'title' => 'Table Saw',
      'field_item_status' => $term_broken->id(),
    ]);
    $node->save();

    // 2. Create a manual maintenance log that marks it as fixed.
    $log = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type' => 'maintenance',
      'asset' => $node->id(),
      'summary' => 'Replaced the motor',
      'confirmed_status' => $term_fixed->id(),
    ]);
    $log->save();

    // 3. Verify the node status was updated.
    $node = Node::load($node->id());
    $this->assertEquals($term_fixed->id(), $node->get('field_item_status')->target_id);

    // 4. Verify no extra status_change log was created.
    // We expect: 1 (initial) + 1 (maintenance).
    $logs = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->loadMultiple();
    $this->assertCount(2, $logs);
    
    $bundles = array_map(fn($l) => $l->bundle(), $logs);
    $this->assertContains('status_change', $bundles);
    $this->assertContains('maintenance', $bundles);
  }

  /**
   * Tests that custom log messages from the node form are captured.
   */
  public function testNodeFormLogMessage(): void {
    $term_up = Term::create(['vid' => 'item_status', 'name' => 'Up']);
    $term_up->save();

    $term_down = Term::create(['vid' => 'item_status', 'name' => 'Down']);
    $term_down->save();

    $node = Node::create([
      'type' => 'item',
      'title' => 'CNC Router',
      'field_item_status' => $term_up->id(),
    ]);
    $node->save();

    // Simulate the form submission attaching a message.
    $node->set('field_item_status', $term_down->id());
    $node->_asset_status_log_message = 'Needs new bits and general cleanup.';
    $node->save();

    $logs = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->loadMultiple();
    $latest_log = end($logs);
    
    $this->assertEquals('Needs new bits and general cleanup.', $latest_log->getDetails());
  }

  /**
   * Tests that maintenance history route access is permission-gated.
   */
  public function testHistoryRouteAccess(): void {
    if (!NodeType::load('page')) {
      NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    }

    $item = Node::create([
      'type' => 'item',
      'title' => 'Bandsaw',
    ]);
    $item->save();

    $page = Node::create([
      'type' => 'page',
      'title' => 'Public Page',
    ]);
    $page->save();

    // Reserve uid 1 so subsequent users do not get superuser bypass.
    User::create([
      'name' => 'bootstrap_admin',
      'status' => 1,
    ])->save();

    Role::create(['id' => 'content_only', 'label' => 'Content only'])
      ->grantPermission('access content')
      ->save();
    $content_only_user = User::create([
      'name' => 'content_only',
      'status' => 1,
    ]);
    $content_only_user->addRole('content_only');
    $content_only_user->save();

    $content_only_access = \Drupal::service('access_manager')->checkNamedRoute(
      'entity.node.asset_status.history',
      ['node' => $item->id()],
      $content_only_user,
      TRUE
    );
    $this->assertTrue($content_only_access->isAllowed(), 'Authenticated users can access item maintenance history.');

    Role::create(['id' => 'asset_reviewer', 'label' => 'Asset reviewer'])
      ->grantPermission('access content')
      ->grantPermission('review asset status reports')
      ->save();
    $reviewer = User::create([
      'name' => 'asset_reviewer',
      'status' => 1,
    ]);
    $reviewer->addRole('asset_reviewer');
    $reviewer->save();

    $reviewer_item_access = \Drupal::service('access_manager')->checkNamedRoute(
      'entity.node.asset_status.history',
      ['node' => $item->id()],
      $reviewer,
      TRUE
    );
    $this->assertTrue($reviewer_item_access->isAllowed(), 'Asset reviewers can access item history.');

    $reviewer_page_access = \Drupal::service('access_manager')->checkNamedRoute(
      'entity.node.asset_status.history',
      ['node' => $page->id()],
      $reviewer,
      TRUE
    );
    $this->assertFalse($reviewer_page_access->isAllowed(), 'Asset history route is denied for non-item nodes.');

    $anonymous_access = \Drupal::service('access_manager')->checkNamedRoute(
      'entity.node.asset_status.history',
      ['node' => $item->id()],
      new AnonymousUserSession(),
      TRUE
    );
    $this->assertFalse($anonymous_access->isAllowed(), 'Anonymous users cannot access item maintenance history.');

    // Verify configurable permission-gated mode as well.
    \Drupal::configFactory()->getEditable('asset_status.settings')
      ->set('history_access_mode', 'permission')
      ->save();

    $content_only_access_permission_mode = \Drupal::service('access_manager')->checkNamedRoute(
      'entity.node.asset_status.history',
      ['node' => $item->id()],
      $content_only_user,
      TRUE
    );
    $this->assertFalse($content_only_access_permission_mode->isAllowed(), 'Content-only users are denied when permission mode is enabled.');

    $reviewer_access_permission_mode = \Drupal::service('access_manager')->checkNamedRoute(
      'entity.node.asset_status.history',
      ['node' => $item->id()],
      $reviewer,
      TRUE
    );
    $this->assertTrue($reviewer_access_permission_mode->isAllowed(), 'Reviewer permissions grant access when permission mode is enabled.');

    Role::create(['id' => 'asset_logger', 'label' => 'Asset logger'])
      ->grantPermission('access content')
      ->grantPermission('log asset maintenance events')
      ->save();
    $logger_user = User::create([
      'name' => 'asset_logger',
      'status' => 1,
    ]);
    $logger_user->addRole('asset_logger');
    $logger_user->save();

    $logger_access_permission_mode = \Drupal::service('access_manager')->checkNamedRoute(
      'entity.node.asset_status.history',
      ['node' => $item->id()],
      $logger_user,
      TRUE
    );
    $this->assertTrue($logger_access_permission_mode->isAllowed(), 'Users with maintenance logging permission can access history in permission mode.');
  }

  /**
   * Tests editing historical logs does not overwrite current status.
   */
  public function testHistoricalEditDoesNotOverwriteCurrentStatus(): void {
    $operational = Term::create(['vid' => 'item_status', 'name' => 'Operational']);
    $operational->save();
    $degraded = Term::create(['vid' => 'item_status', 'name' => 'Degraded']);
    $degraded->save();
    $out = Term::create(['vid' => 'item_status', 'name' => 'Out of Service']);
    $out->save();

    $node = Node::create([
      'type' => 'item',
      'title' => 'Planer',
      'field_item_status' => $operational->id(),
    ]);
    $node->save();

    $old_log = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type' => 'maintenance',
      'asset' => $node->id(),
      'summary' => 'Older repair note',
      'confirmed_status' => $degraded->id(),
      'created' => time() - 3600,
    ]);
    $old_log->save();

    $latest_log = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type' => 'maintenance',
      'asset' => $node->id(),
      'summary' => 'Latest outage',
      'confirmed_status' => $out->id(),
      'created' => time(),
    ]);
    $latest_log->save();

    $node = Node::load($node->id());
    $this->assertEquals($out->id(), $node->get('field_item_status')->target_id);

    // Editing the older log should not move the current node status backwards.
    $old_log->setSummary('Older repair note edited');
    $old_log->save();

    $node = Node::load($node->id());
    $this->assertEquals($out->id(), $node->get('field_item_status')->target_id);
  }

  /**
   * Tests fallback actor attribution uses the asset owner when anonymous.
   */
  public function testFallbackActorUsesAssetOwner(): void {
    $owner = User::create([
      'name' => 'tool_owner',
      'status' => 1,
    ]);
    $owner->save();

    $up = Term::create(['vid' => 'item_status', 'name' => 'Up']);
    $up->save();
    $down = Term::create(['vid' => 'item_status', 'name' => 'Down']);
    $down->save();

    $node = Node::create([
      'type' => 'item',
      'title' => 'Drill Press',
      'uid' => $owner->id(),
      'field_item_status' => $up->id(),
    ]);
    $node->save();

    \Drupal::service('asset_status.status_change_logger')->handleNodeStatusChange($node, $up, $down, [
      'is_new' => FALSE,
    ]);

    $ids = \Drupal::entityTypeManager()->getStorage('asset_log_entry')
      ->getQuery()
      ->condition('asset', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertNotEmpty($ids);

    $latest = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->load(reset($ids));
    $this->assertEquals((int) $owner->id(), (int) $latest->getOwnerId());
  }

  /**
   * Tests history and admin list rendering when a log owner is missing.
   */
  public function testRenderingWithMissingOwner(): void {
    $status = Term::create(['vid' => 'item_status', 'name' => 'Operational']);
    $status->save();

    $node = Node::create([
      'type' => 'item',
      'title' => 'Lathe',
      'field_item_status' => $status->id(),
    ]);
    $node->save();

    $temp_user = User::create([
      'name' => 'temp_owner',
      'status' => 1,
    ]);
    $temp_user->save();

    $log = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type' => 'maintenance',
      'asset' => $node->id(),
      'summary' => 'Lubricated spindle',
      'confirmed_status' => $status->id(),
      'user_id' => $temp_user->id(),
    ]);
    $log->save();

    // Remove the owner to simulate stale/orphan references.
    $temp_user->delete();
    $log = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->load($log->id());

    $controller = new AssetLogController(
      \Drupal::entityTypeManager(),
      \Drupal::service('entity.form_builder')
    );
    $build = $controller->history($node);
    $this->assertNotEmpty($build['#rows']);
    $this->assertEquals('Unknown user', (string) $build['#rows'][0][1]['data']);

    $entity_type = \Drupal::entityTypeManager()->getDefinition('asset_log_entry');
    $list_builder = AssetLogEntryListBuilder::createInstance(\Drupal::getContainer(), $entity_type);
    $row = $list_builder->buildRow($log);
    $this->assertEquals('Unknown user', (string) $row['user_id']);
  }

  /**
   * Helper to create the item content type and field.
   */
  private function createItemContentType(): void {
    // Create 'item' content type.
    if (!\Drupal::entityTypeManager()->getStorage('node_type')->load('item')) {
      NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
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
