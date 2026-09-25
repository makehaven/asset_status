<?php

declare(strict_types=1);

namespace Drupal\asset_status\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;

/**
 * Finds tools stuck in a non-operational status and nudges staff about them.
 *
 * The Tool Status Board already shows "time in status"; what was missing was
 * anything that comes to the shop manager instead of waiting to be looked at.
 * On 2026-09-14 two tools had sat in "Reported Concern" for 76 and 132 days
 * with nobody confirming or clearing them. This service lists such tools and,
 * once a day from cron, posts a short Slack reminder for each one with the
 * quick-update link, repeating weekly until someone updates the record.
 *
 * "Stale" means: published item, status not usable and not an administrative
 * parking state (Storage, Gone, Setup), and no log entry of any kind for at
 * least the per-status threshold. Any new log entry — a note, a status change,
 * an inspection — resets the clock, because that is exactly the behaviour we
 * want to reward.
 */
class StaleStatusMonitor {

  use StringTranslationTrait;

  /**
   * Statuses that are administrative, not "broken": never nudged.
   */
  public const PARKED_STATUSES = [
    'Storage',
    'Gone',
    'Offline - Initial Setup',
    'Setup / Training Only',
    'Setup',
  ];

  /**
   * State key holding the last cron run timestamp.
   */
  public const STATE_LAST_RUN = 'asset_status.stale_last_run';

  /**
   * State key prefix for per-tool last-nudge timestamps.
   */
  public const STATE_NUDGE_PREFIX = 'asset_status.stale_nudged.';

  /**
   * Thresholds used when the settings carry none (pre-existing installs).
   */
  public const DEFAULT_STALE_DAYS = [
    'Reported Concern' => 14,
    'Degraded' => 30,
    'Offline for Maintenance' => 7,
    'Out of Service' => 30,
    'Maintenance' => 7,
  ];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Lists every tool currently past its stale threshold.
   *
   * @return array<int, array>
   *   Keyed by nid: nid, title, status, days, since (timestamp),
   *   threshold, expected_back (Y-m-d|null), overdue (bool),
   *   last_nudged (timestamp|null), due (bool: a nudge is due now).
   */
  public function findStale(): array {
    $settings = $this->configFactory->get('asset_status.settings');
    $thresholds = (array) ($settings->get('stale_days') ?: self::DEFAULT_STALE_DAYS);
    $repeat_days = max(1, (int) ($settings->get('stale_repeat_days') ?: 7));
    $now = $this->time->getRequestTime();

    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_item_status', 's', 's.entity_id = n.nid AND s.deleted = 0');
    $query->join('taxonomy_term_field_data', 't', 't.tid = s.field_item_status_target_id');
    $query->fields('n', ['nid', 'title', 'changed']);
    $query->addField('t', 'name', 'status');
    $query->condition('n.type', 'item');
    $query->condition('n.status', 1);
    $rows = $query->execute()->fetchAll();

    $candidates = [];
    foreach ($rows as $row) {
      $label = (string) $row->status;
      if ($label === 'Operational' || in_array($label, self::PARKED_STATUSES, TRUE)) {
        continue;
      }
      $candidates[(int) $row->nid] = $row;
    }
    if (!$candidates) {
      return [];
    }

    $latest = $this->loadLatestLogEntries(array_keys($candidates));

    $stale = [];
    foreach ($candidates as $nid => $row) {
      $label = (string) $row->status;
      $threshold = (int) ($thresholds[$label] ?? 0);
      if ($threshold <= 0) {
        // No threshold configured for this status: not monitored.
        continue;
      }
      $entry = $latest[$nid] ?? NULL;
      $since = $entry ? (int) $entry['created'] : (int) $row->changed;
      $days = (int) floor(($now - $since) / 86400);
      if ($days < $threshold) {
        continue;
      }
      $expected_back = $entry['expected_back'] ?? NULL;
      $overdue = $expected_back !== NULL && strtotime($expected_back . ' 23:59:59') < $now;
      $last_nudged = $this->state->get(self::STATE_NUDGE_PREFIX . $nid);
      $due = !$last_nudged || ($now - (int) $last_nudged) >= $repeat_days * 86400;

      $stale[$nid] = [
        'nid' => $nid,
        'title' => (string) $row->title,
        'status' => $label,
        'days' => $days,
        'since' => $since,
        'threshold' => $threshold,
        'expected_back' => $expected_back,
        'overdue' => $overdue,
        'last_nudged' => $last_nudged ? (int) $last_nudged : NULL,
        'due' => $due,
      ];
    }

    uasort($stale, static fn(array $a, array $b): int => $b['days'] <=> $a['days']);
    return $stale;
  }

  /**
   * Posts a nudge for every stale tool whose repeat interval has elapsed.
   *
   * @param bool $dry_run
   *   When TRUE, nothing is posted or recorded; the messages are returned.
   *
   * @return array<int, string>
   *   Keyed by nid: the message that was (or would be) posted.
   */
  public function nudge(bool $dry_run = FALSE): array {
    $settings = $this->configFactory->get('asset_status.settings');
    if (!$dry_run && !$settings->get('stale_nudge_enabled')) {
      return [];
    }

    $sent = [];
    foreach ($this->findStale() as $nid => $item) {
      if (!$item['due']) {
        continue;
      }
      $message = $this->buildMessage($item);
      $sent[$nid] = $message;
      if ($dry_run) {
        continue;
      }
      // Only record the nudge if something actually went out. Marking it sent
      // regardless is how the 2026-09-14 release's own acceptance silently
      // failed: the Slack webhook had been blanked by that deploy's
      // `cim --partial`, every post was skipped, and the tools were still
      // stamped "nudged" — so they would not have been retried for a week and
      // the log said the run had nudged them.
      $delivered = FALSE;
      foreach ($this->channelsFor($nid) as $channel) {
        $delivered = $this->post($channel, $message) || $delivered;
      }
      if ($delivered) {
        $this->state->set(self::STATE_NUDGE_PREFIX . $nid, $this->time->getRequestTime());
      }
      else {
        unset($sent[$nid]);
      }
    }

    if (!$dry_run) {
      $this->state->set(self::STATE_LAST_RUN, $this->time->getRequestTime());
      $this->loggerFactory->get('asset_status')->notice('Stale-status run: @n tool(s) nudged.', ['@n' => count($sent)]);
    }
    return $sent;
  }

  /**
   * Runs the nudge at most once per day; called from hook_cron().
   */
  public function runFromCron(): void {
    $last = (int) $this->state->get(self::STATE_LAST_RUN, 0);
    if ($this->time->getRequestTime() - $last < 20 * 3600) {
      return;
    }
    $this->nudge();
  }

  /**
   * The Slack text for one stale tool.
   */
  public function buildMessage(array $item): string {
    $update_url = Url::fromRoute('asset_status.quick_status_update', ['node' => $item['nid']], ['absolute' => TRUE])->toString();
    $tool_url = Url::fromRoute('entity.node.canonical', ['node' => $item['nid']], ['absolute' => TRUE])->toString();
    $since = date('M j', $item['since']);

    $line = sprintf('%s has been *%s* for %d days (last note %s).', $item['title'], $item['status'], $item['days'], $since);
    if ($item['expected_back']) {
      $line .= $item['overdue']
        ? sprintf(' It was expected back %s and is now overdue.', date('M j', strtotime($item['expected_back'])))
        : sprintf(' Expected back %s.', date('M j', strtotime($item['expected_back'])));
    }
    $line .= ' Still true?';
    return $line . "\nUpdate: " . $update_url . ' · Tool: ' . $tool_url;
  }

  /**
   * Channels a nudge for this tool goes to.
   *
   * Only the configured maintenance channel. The tool's own channel (or its
   * area channel) is a member channel: asking "still true?" there puts a staff
   * chore in front of members, who cannot update the record and should not be
   * asked to (Lior, 2026-09-24, about #laser-cutting). The original status post
   * still goes to the tool channel from slack_asset_status_change; only this
   * repeating reminder stays with staff. The tool's channel is used only when
   * no maintenance channel is configured, so a reminder always reaches
   * somebody.
   *
   * @return string[]
   *   Channel names with a leading '#'.
   */
  public function channelsFor(int $nid): array {
    $shared = trim((string) $this->configFactory->get('asset_status.settings')->get('stale_slack_channel'));
    if ($shared !== '') {
      return ['#' . ltrim($shared, '#')];
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      return [];
    }
    $own = '';
    if ($node->hasField('field_item_slack_channel') && !$node->get('field_item_slack_channel')->isEmpty()) {
      $own = (string) $node->get('field_item_slack_channel')->value;
    }
    elseif ($node->hasField('field_item_area_interest') && !$node->get('field_item_area_interest')->isEmpty()) {
      $term = $node->get('field_item_area_interest')->entity;
      if ($term && $term->hasField('field_interest_slack_channel') && !$term->get('field_interest_slack_channel')->isEmpty()) {
        $own = (string) $term->get('field_interest_slack_channel')->value;
      }
    }
    $own = trim($own);
    return $own === '' ? [] : ['#' . ltrim($own, '#')];
  }

  /**
   * Posts one message through the shared Slack Connector webhook.
   *
   * Only live posts to Slack, matching slack_asset_status_change: dev, test
   * and Lando log the message instead, so rehearsals never page anyone.
   */
  protected function post(string $channel, string $message): bool {
    $logger = $this->loggerFactory->get('asset_status');
    $env = $_ENV['PANTHEON_ENVIRONMENT'] ?? getenv('PANTHEON_ENVIRONMENT') ?: NULL;
    if ($env !== 'live') {
      $logger->notice('Stale nudge (not posted on @env) to @channel: @message', [
        '@env' => $env ?? 'local',
        '@channel' => $channel,
        '@message' => $message,
      ]);
      // Off-live is a deliberate no-op, not a failure: treat it as delivered so
      // dev and test do not accumulate a permanent backlog of "due" nudges.
      return TRUE;
    }
    $webhook = (string) $this->configFactory->get('slack_connector.settings')->get('webhook_url');
    if ($webhook === '') {
      $logger->error('Stale nudge skipped: Slack Connector webhook is not configured.');
      return FALSE;
    }
    try {
      $this->httpClient->post($webhook, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => ['channel' => $channel, 'text' => $message],
      ]);
      return TRUE;
    }
    catch (\Throwable $e) {
      $logger->error('Stale nudge to @channel failed: @error', ['@channel' => $channel, '@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Newest log entry per asset, with its created time and expected-back date.
   *
   * @return array<int, array{created:int, expected_back:?string}>
   *   Keyed by asset nid.
   */
  protected function loadLatestLogEntries(array $nids): array {
    $sub = $this->database->select('asset_log_entry', 'm');
    $sub->addField('m', 'asset');
    $sub->addExpression('MAX(m.created)', 'latest');
    $sub->condition('m.asset', $nids, 'IN');
    $sub->groupBy('m.asset');

    $query = $this->database->select('asset_log_entry', 'l');
    $query->join($sub, 'x', 'x.asset = l.asset AND x.latest = l.created');
    $query->fields('l', ['asset', 'created']);
    $has_expected = $this->database->schema()->fieldExists('asset_log_entry', 'expected_back');
    if ($has_expected) {
      $query->addField('l', 'expected_back');
    }
    $result = [];
    foreach ($query->execute() as $row) {
      $result[(int) $row->asset] = [
        'created' => (int) $row->created,
        'expected_back' => $has_expected && !empty($row->expected_back) ? substr((string) $row->expected_back, 0, 10) : NULL,
      ];
    }
    return $result;
  }

}
