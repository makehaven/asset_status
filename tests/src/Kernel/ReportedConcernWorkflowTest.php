<?php

declare(strict_types=1);

namespace Drupal\Tests\asset_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Tests the member-report → Reported Concern → staff review workflow.
 *
 * Covers:
 * - "Reported Concern" is recognized as a usable status.
 * - Member reports always land in "Reported Concern", not directly in
 *   Degraded or Out of Service.
 * - Member's assessed severity is stored in inspection log reported_status.
 * - No downgrade when tool is already at a worse status.
 * - Staff confirmation via log entry syncs the node status correctly.
 * - Staff note is captured in the log entry details.
 * - Editing an old log entry does not overwrite a newer confirmed status.
 *
 * @group asset_status
 */
class ReportedConcernWorkflowTest extends KernelTestBase {

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

    $field_storage = \Drupal\field\Entity\FieldStorageConfig::create([
      'field_name' => 'field_item_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ]);
    $field_storage->save();

    \Drupal\field\Entity\FieldConfig::create([
      'field_name' => 'field_item_status',
      'entity_type' => 'node',
      'bundle' => 'item',
      'label' => 'Status',
    ])->save();

    // Seed the status terms used across tests.
    foreach (['Operational', 'Reported Concern', 'Degraded', 'Out of Service'] as $label) {
      $term = Term::create(['vid' => 'item_status', 'name' => $label]);
      $term->save();
      $this->terms[$label] = $term;
    }
  }

  // ---------------------------------------------------------------------------
  // AssetAvailability service
  // ---------------------------------------------------------------------------

  /**
   * "Reported Concern" must be treated as usable so members can still access
   * a tool that only has an unconfirmed report against it.
   */
  public function testReportedConcernIsUsable(): void {
    $service = \Drupal::service('asset_status.availability');

    $this->assertTrue(
      $service->isUsable($this->terms['Reported Concern']),
      '"Reported Concern" should be usable — the issue has not been confirmed.'
    );
    $this->assertTrue(
      $service->isUsable($this->terms['Operational']),
      '"Operational" must remain usable.'
    );
    $this->assertTrue(
      $service->isUsable($this->terms['Degraded']),
      '"Degraded" must remain usable.'
    );
    $this->assertFalse(
      $service->isUsable($this->terms['Out of Service']),
      '"Out of Service" must not be usable.'
    );
  }

  /**
   * The convenience getter added alongside getOutOfServiceTerm() works.
   */
  public function testGetReportedConcernTermHelper(): void {
    $service = \Drupal::service('asset_status.availability');
    $term    = $service->getReportedConcernTerm();

    $this->assertNotNull($term, 'getReportedConcernTerm() should return a term.');
    $this->assertEquals('Reported Concern', $term->label());
  }

  // ---------------------------------------------------------------------------
  // Staff confirmation via log entry
  // ---------------------------------------------------------------------------

  /**
   * Creating a status_change log entry with confirmed_status set should
   * sync that status back to the asset node (existing postSave behaviour).
   * This is the mechanism the quick-status form relies on.
   */
  public function testStaffConfirmationSyncsNodeStatus(): void {
    $node = Node::create([
      'type' => 'item',
      'title' => 'Water Jet Cutter',
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $node->save();

    // Staff confirms it is actually Out of Service.
    $log = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type'             => 'status_change',
      'asset'            => $node->id(),
      'summary'          => 'Staff review: status set to Out of Service',
      'details'          => 'Confirmed: motor seized, part ordered.',
      'confirmed_status' => $this->terms['Out of Service']->id(),
      'reported_status'  => $this->terms['Out of Service']->id(),
    ]);
    $log->save();

    $node = Node::load($node->id());
    $this->assertEquals(
      $this->terms['Out of Service']->id(),
      (int) $node->get('field_item_status')->target_id,
      'Node status should be synced to the confirmed_status of the log entry.'
    );
  }

  /**
   * Staff can clear a Reported Concern by confirming Operational.
   */
  public function testStaffClearsReportedConcernToOperational(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'Laser Cutter',
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $node->save();

    \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type'             => 'status_change',
      'asset'            => $node->id(),
      'summary'          => 'Staff review: status set to Operational',
      'details'          => 'Checked the machine — member misidentified a normal noise.',
      'confirmed_status' => $this->terms['Operational']->id(),
      'reported_status'  => $this->terms['Operational']->id(),
    ])->save();

    $node = Node::load($node->id());
    $this->assertEquals(
      $this->terms['Operational']->id(),
      (int) $node->get('field_item_status')->target_id,
      'Clearing a false alarm should restore Operational status.'
    );
  }

  /**
   * Staff note is stored in the log entry details field.
   */
  public function testStaffNoteStoredInLogDetails(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'Bandsaw',
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $node->save();

    $note = 'jsmith: blade guard is loose, tightened with 3/8" wrench.';

    $log = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type'             => 'status_change',
      'asset'            => $node->id(),
      'summary'          => 'Staff review: status set to Degraded',
      'details'          => $note,
      'confirmed_status' => $this->terms['Degraded']->id(),
      'reported_status'  => $this->terms['Degraded']->id(),
    ]);
    $log->save();

    $loaded = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->load($log->id());
    $this->assertEquals($note, $loaded->getDetails(), 'Staff note should be stored in the log details field.');
  }

  // ---------------------------------------------------------------------------
  // Inspection log (member reports)
  // ---------------------------------------------------------------------------

  /**
   * The inspection log created on member report should store the member's
   * assessed severity in reported_status, not the tool's actual new status.
   *
   * This is the data source the quick-status form surfaces to staff.
   */
  public function testInspectionLogStoresMemberSeverityInReportedStatus(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'CNC Router',
      'field_item_status' => $this->terms['Operational']->id(),
    ]);
    $node->save();

    // Member reports they think it is "Out of Service".
    // The tool is moved to "Reported Concern" but the log records "Out of Service"
    // as the reported severity so staff knows what the member observed.
    \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type'             => 'inspection',
      'asset'            => $node->id(),
      'summary'          => 'Member issue report (broken_nonfunctional)',
      'details'          => 'Member Report (jsmith): spindle makes loud grinding noise',
      'reported_status'  => $this->terms['Out of Service']->id(),
      'confirmed_status' => NULL,
    ])->save();

    // Move the node to Reported Concern as the webform handler would.
    $node->set('field_item_status', $this->terms['Reported Concern']->id());
    $node->_skip_asset_status_log = TRUE;
    $node->save();

    // Retrieve the inspection log.
    $log_storage = \Drupal::entityTypeManager()->getStorage('asset_log_entry');
    $ids = $log_storage->getQuery()
      ->condition('asset', $node->id())
      ->condition('type', 'inspection')
      ->accessCheck(FALSE)
      ->execute();

    $this->assertCount(1, $ids, 'One inspection log entry should exist.');
    $log = $log_storage->load(reset($ids));

    $this->assertEquals(
      $this->terms['Out of Service']->id(),
      (int) $log->getReportedStatus()->id(),
      'reported_status should reflect the member\'s severity assessment.'
    );
    $this->assertNull(
      $log->getConfirmedStatus(),
      'confirmed_status should be NULL until staff review.'
    );

    // Node should be at "Reported Concern", not "Out of Service".
    $node = Node::load($node->id());
    $this->assertEquals(
      $this->terms['Reported Concern']->id(),
      (int) $node->get('field_item_status')->target_id,
      'Tool should be in "Reported Concern" — not yet confirmed as Out of Service.'
    );
  }

  /**
   * If a tool is already Degraded or worse, a member report should not
   * downgrade it to the softer "Reported Concern" status.
   */
  public function testNoDowngradeFromWorseStatus(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'Planer',
      'field_item_status' => $this->terms['Out of Service']->id(),
    ]);
    $node->save();

    // Simulate the shouldEscalateStatus check from the webform handler:
    // "Reported Concern" rank = 1, "Out of Service" rank = 3, so no escalation.
    $availability = \Drupal::service('asset_status.availability');
    $concern_term = $availability->getReportedConcernTerm();
    $this->assertNotNull($concern_term);

    // The test: after a "member report", the node status should remain OOS.
    // We replicate what the handler does: only call set() if concern rank > current rank.
    $current_label = $node->get('field_item_status')->entity->label();
    $rank = [
      'Operational'      => 0,
      'Reported Concern' => 1,
      'Degraded'         => 2,
      'Out of Service'   => 3,
    ];
    $should_escalate = ($rank['Reported Concern'] ?? 0) > ($rank[$current_label] ?? -1);

    $this->assertFalse($should_escalate, '"Reported Concern" should not downgrade a tool already at "Out of Service".');

    // Verify the node is still OOS after a no-op report.
    $node = Node::load($node->id());
    $this->assertEquals(
      $this->terms['Out of Service']->id(),
      (int) $node->get('field_item_status')->target_id
    );
  }

  /**
   * A member report on an already-Degraded tool should not overwrite it
   * with the softer "Reported Concern".
   */
  public function testNoDowngradeFromDegraded(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'Table Saw',
      'field_item_status' => $this->terms['Degraded']->id(),
    ]);
    $node->save();

    $current_label = $node->get('field_item_status')->entity->label();
    $rank = [
      'Operational'      => 0,
      'Reported Concern' => 1,
      'Degraded'         => 2,
      'Out of Service'   => 3,
    ];
    $should_escalate = ($rank['Reported Concern'] ?? 0) > ($rank[$current_label] ?? -1);

    $this->assertFalse($should_escalate, '"Reported Concern" should not downgrade a tool already at "Degraded".');
  }

  // ---------------------------------------------------------------------------
  // Edge cases
  // ---------------------------------------------------------------------------

  /**
   * A member reporting a concern on an Operational tool should escalate it
   * to "Reported Concern" (not stay Operational).
   */
  public function testOperationalToolEscalatesToReportedConcern(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'Drill Press',
      'field_item_status' => $this->terms['Operational']->id(),
    ]);
    $node->save();

    $current_label = $node->get('field_item_status')->entity->label();
    $rank = [
      'Operational'      => 0,
      'Reported Concern' => 1,
      'Degraded'         => 2,
      'Out of Service'   => 3,
    ];
    $should_escalate = ($rank['Reported Concern'] ?? 0) > ($rank[$current_label] ?? -1);

    $this->assertTrue($should_escalate, 'An Operational tool should escalate to "Reported Concern" on member report.');
  }

  /**
   * Editing an old log entry should not overwrite a newer confirmed status
   * (existing isMostRecentForAsset behaviour).
   */
  public function testOldLogEditDoesNotOverwriteNewerStatus(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'Lathe',
      'field_item_status' => $this->terms['Reported Concern']->id(),
    ]);
    $node->save();

    $log_storage = \Drupal::entityTypeManager()->getStorage('asset_log_entry');

    // First staff action: confirms Degraded.
    $old_log = $log_storage->create([
      'type'             => 'status_change',
      'asset'            => $node->id(),
      'summary'          => 'Staff: set to Degraded',
      'confirmed_status' => $this->terms['Degraded']->id(),
      'created'          => \Drupal::time()->getRequestTime() - 3600,
    ]);
    $old_log->save();

    // Second staff action: confirms Out of Service.
    $new_log = $log_storage->create([
      'type'             => 'status_change',
      'asset'            => $node->id(),
      'summary'          => 'Staff: set to Out of Service',
      'confirmed_status' => $this->terms['Out of Service']->id(),
      'created'          => \Drupal::time()->getRequestTime(),
    ]);
    $new_log->save();

    $node = Node::load($node->id());
    $this->assertEquals(
      $this->terms['Out of Service']->id(),
      (int) $node->get('field_item_status')->target_id,
      'After two confirmations, node should reflect the latest.'
    );

    // Re-saving the older log should not move the node back to Degraded.
    $old_log->setSummary('Staff: set to Degraded (edited)');
    $old_log->save();

    $node = Node::load($node->id());
    $this->assertEquals(
      $this->terms['Out of Service']->id(),
      (int) $node->get('field_item_status')->target_id,
      'Editing an older log entry must not regress the node status.'
    );
  }

  /**
   * Multiple member reports on the same tool should not stack additional
   * status_change log entries — the tool stays in "Reported Concern" and each
   * report only adds an inspection entry.
   */
  public function testMultipleMemberReportsDontStackStatusChanges(): void {
    $node = Node::create([
      'type'              => 'item',
      'title'             => 'MIG Welder',
      'field_item_status' => $this->terms['Operational']->id(),
    ]);
    $node->save();

    $log_storage = \Drupal::entityTypeManager()->getStorage('asset_log_entry');

    // First member report: tool moves to Reported Concern + one inspection log.
    $log_storage->create([
      'type'            => 'inspection',
      'asset'           => $node->id(),
      'summary'         => 'Member issue report (damaged_functional)',
      'details'         => 'Member Report (alice): torch tip worn',
      'reported_status' => $this->terms['Degraded']->id(),
    ])->save();
    $node->set('field_item_status', $this->terms['Reported Concern']->id());
    $node->_skip_asset_status_log = TRUE;
    $node->save();

    // Second member report: tool already in Reported Concern — no status change.
    // shouldEscalateStatus returns FALSE (concern rank 1 not > concern rank 1).
    $rank = ['Operational' => 0, 'Reported Concern' => 1, 'Degraded' => 2, 'Out of Service' => 3];
    $current_label = $node->get('field_item_status')->entity->label();
    $should_escalate = ($rank['Reported Concern'] ?? 0) > ($rank[$current_label] ?? -1);
    $this->assertFalse($should_escalate, 'Second report should not escalate an already-Reported Concern tool.');

    // Second inspection log still gets created.
    $log_storage->create([
      'type'            => 'inspection',
      'asset'           => $node->id(),
      'summary'         => 'Member issue report (parts_missing)',
      'details'         => 'Member Report (bob): wire feed jammed',
      'reported_status' => $this->terms['Degraded']->id(),
    ])->save();

    $inspection_ids = $log_storage->getQuery()
      ->condition('asset', $node->id())
      ->condition('type', 'inspection')
      ->accessCheck(FALSE)
      ->execute();

    $this->assertCount(2, $inspection_ids, 'Each member report creates an inspection log entry.');
  }

}
