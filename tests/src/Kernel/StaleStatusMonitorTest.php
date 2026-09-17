<?php

declare(strict_types=1);

namespace Drupal\Tests\asset_status\Kernel;

use Drupal\asset_status\Service\StaleStatusMonitor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;

/**
 * Tests the stale-status monitor: thresholds, clock reset, overdue, repeats.
 *
 * @group asset_status
 */
class StaleStatusMonitorTest extends KernelTestBase {

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
    // Real module rather than a bare config write: post() reads its
    // webhook_url, and this test's whole point is what happens when that is
    // empty, so the schema needs to be genuinely present.
    'slack_connector',
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
    $this->installEntitySchema('file');
    $this->installEntitySchema('asset_log_entry');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['asset_status', 'node', 'system']);

    Vocabulary::create(['vid' => 'item_status', 'name' => 'Item Status'])->save();
    if (!\Drupal::entityTypeManager()->getStorage('node_type')->load('item')) {
      NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_item_status',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_item_status',
      'entity_type' => 'node',
      'bundle' => 'item',
      'label' => 'Status',
    ])->save();
    foreach (['Operational', 'Reported Concern', 'Offline for Maintenance', 'Storage'] as $label) {
      $term = Term::create(['vid' => 'item_status', 'name' => $label]);
      $term->save();
      $this->terms[$label] = $term;
    }
    User::create(['name' => 'staff', 'mail' => 'staff@example.com', 'status' => 1])->save();

    $this->config('asset_status.settings')
      ->set('stale_nudge_enabled', TRUE)
      ->set('stale_slack_channel', '#broken')
      ->set('stale_repeat_days', 7)
      ->set('stale_days', ['Reported Concern' => 14, 'Offline for Maintenance' => 7])
      ->save();
  }

  /**
   * Creates a published item in the given status with a backdated log entry.
   */
  private function tool(string $title, string $status, int $days_ago, ?string $expected_back = NULL): Node {
    $node = Node::create(['type' => 'item', 'title' => $title, 'status' => 1]);
    $node->_skip_asset_status_log = TRUE;
    $node->set('field_item_status', $this->terms[$status]->id());
    $node->save();
    $entry = \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type' => 'status_change',
      'asset' => $node->id(),
      'summary' => 'seed',
      'details' => 'seed',
      'confirmed_status' => $this->terms[$status]->id(),
      'reported_status' => $this->terms[$status]->id(),
      'user_id' => 1,
      'expected_back' => $expected_back,
    ]);
    $entry->setCreatedTime(\Drupal::time()->getRequestTime() - $days_ago * 86400);
    $entry->save();
    return $node;
  }

  /**
   * Only tools past their per-status threshold are stale; parked never are.
   */
  public function testThresholdsAndParkedStatuses(): void {
    $stale_concern = $this->tool('Laser', 'Reported Concern', 30);
    $this->tool('Bandsaw', 'Reported Concern', 5);
    $stale_offline = $this->tool('Table Saw', 'Offline for Maintenance', 9, '2020-01-01');
    $this->tool('Old Printer', 'Storage', 400);
    $this->tool('Lathe', 'Operational', 400);

    /** @var \Drupal\asset_status\Service\StaleStatusMonitor $monitor */
    $monitor = \Drupal::service('asset_status.stale_monitor');
    $stale = $monitor->findStale();

    $this->assertSame([(int) $stale_concern->id(), (int) $stale_offline->id()], array_keys($stale));
    $this->assertSame(30, $stale[(int) $stale_concern->id()]['days']);
    $this->assertTrue($stale[(int) $stale_offline->id()]['overdue']);
    $this->assertStringContainsString('now overdue', $monitor->buildMessage($stale[(int) $stale_offline->id()]));
    $this->assertStringContainsString('Still true?', $monitor->buildMessage($stale[(int) $stale_concern->id()]));
  }

  /**
   * Any new log entry resets the clock, and repeats respect the interval.
   */
  public function testNewEntryResetsClockAndRepeatInterval(): void {
    $node = $this->tool('Laser', 'Reported Concern', 30);
    /** @var \Drupal\asset_status\Service\StaleStatusMonitor $monitor */
    $monitor = \Drupal::service('asset_status.stale_monitor');

    // Dry run posts nothing and records nothing.
    $this->assertCount(1, $monitor->nudge(TRUE));
    $this->assertNull(\Drupal::state()->get(StaleStatusMonitor::STATE_NUDGE_PREFIX . $node->id()));

    // A real run (non-live: logs instead of posting) records the nudge and
    // the same tool is not due again inside the repeat window.
    $this->assertCount(1, $monitor->nudge());
    $this->assertNotNull(\Drupal::state()->get(StaleStatusMonitor::STATE_NUDGE_PREFIX . $node->id()));
    $this->assertCount(0, $monitor->nudge());
    $this->assertSame(['#broken'], $monitor->channelsFor((int) $node->id()));

    // A fresh note (any bundle, status unchanged) makes it not stale at all.
    \Drupal::entityTypeManager()->getStorage('asset_log_entry')->create([
      'type' => 'maintenance',
      'asset' => $node->id(),
      'summary' => 'Ordered the part',
      'details' => 'Part on order, two weeks.',
      'user_id' => 1,
    ])->save();
    $this->assertSame([], $monitor->findStale());
  }

  /**
   * Disabled setting: cron path posts nothing, but the preview still lists.
   */
  public function testDisabledStillPreviews(): void {
    $this->tool('Laser', 'Reported Concern', 30);
    $this->config('asset_status.settings')->set('stale_nudge_enabled', FALSE)->save();
    /** @var \Drupal\asset_status\Service\StaleStatusMonitor $monitor */
    $monitor = \Drupal::service('asset_status.stale_monitor');
    $this->assertCount(1, $monitor->findStale());
    $this->assertCount(1, $monitor->nudge(TRUE));
    $this->assertCount(0, $monitor->nudge());
  }


  /**
   * A nudge that was not delivered must not be recorded as sent.
   *
   * This is the defect that made the 2026-09-14 release's own acceptance
   * silently pass. That deploy's `cim --partial` blanked the Slack Connector
   * webhook, so every post was skipped — but the tools were stamped "nudged"
   * anyway and the run logged a nudge count. The two tools the release was
   * written for would not have been retried for a week, and nothing said so.
   *
   * Simulated by pretending to be live with no webhook configured, which is
   * exactly the state live was in from 2026-09-15 to 09-17.
   */
  public function testUndeliveredNudgeIsNotRecordedAsSent(): void {
    $node = $this->tool('Laser', 'Reported Concern', 30);
    /** @var \Drupal\asset_status\Service\StaleStatusMonitor $monitor */
    $monitor = \Drupal::service('asset_status.stale_monitor');

    $original = $_ENV['PANTHEON_ENVIRONMENT'] ?? NULL;
    $_ENV['PANTHEON_ENVIRONMENT'] = 'live';
    $this->installConfig(['slack_connector']);
    $this->config('slack_connector.settings')->set('webhook_url', '')->save();

    try {
      $sent = $monitor->nudge();

      $this->assertCount(0, $sent, 'An undelivered nudge must not be counted as sent.');
      $this->assertNull(
        \Drupal::state()->get(StaleStatusMonitor::STATE_NUDGE_PREFIX . $node->id()),
        'An undelivered nudge must not stamp the tool as nudged.'
      );

      // And because nothing was recorded, it is still due on the next run
      // rather than suppressed for the repeat window.
      $stale = $monitor->findStale();
      $this->assertTrue($stale[(int) $node->id()]['due'], 'The tool must still be due.');
    }
    finally {
      if ($original === NULL) {
        unset($_ENV['PANTHEON_ENVIRONMENT']);
      }
      else {
        $_ENV['PANTHEON_ENVIRONMENT'] = $original;
      }
    }
  }

}
