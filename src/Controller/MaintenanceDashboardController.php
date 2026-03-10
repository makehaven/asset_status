<?php

declare(strict_types=1);

namespace Drupal\asset_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the staff maintenance queue dashboard.
 *
 * Shows tools in "Reported Concern" (pending staff review) and tools
 * that are currently Degraded or Out of Service, with one-click actions
 * to update status and add staff notes.
 */
final class MaintenanceDashboardController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Status labels that are NOT maintenance issues and should be excluded from
   * the "offline" section of the dashboard. "Gone" = tool removed from space;
   * "Storage"/"Setup" = administrative; "Operational"/"Reported Concern" are
   * handled separately.
   */
  private const EXCLUDED_FROM_OFFLINE = [
    'Operational',
    'Reported Concern',
    'Gone',
    'Storage',
    'Setup',
  ];

  /**
   * Renders the maintenance dashboard.
   */
  public function dashboard(): array {
    $concern_term = $this->getTermByLabel('Reported Concern');
    $offline_terms = $this->getOfflineTerms();

    $queue_nodes = $concern_term ? $this->getNodesByStatus([$concern_term]) : [];
    $down_nodes  = $this->getNodesByStatus($offline_terms);

    // Stats: count by severity bucket.
    $degraded_count = 0;
    $oos_count = 0;
    foreach ($down_nodes as $node) {
      $term = $node->get('field_item_status')->entity;
      $label = $term ? $term->label() : '';
      // Treat "Out of Service", "Gone", and any hard-down equivalents as OOS.
      if (in_array($label, ['Out of Service', 'Gone', 'Offline for Maintenance'], TRUE)) {
        $oos_count++;
      }
      else {
        $degraded_count++;
      }
    }

    $build = [];

    // Stats bar.
    $build['stats'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['maintenance-stats-bar']],
      'queue_stat' => [
        '#markup' => '<div class="stat-item stat-concern"><span class="stat-number">' . count($queue_nodes) . '</span><span class="stat-label">Needs Review</span></div>',
      ],
      'degraded_stat' => [
        '#markup' => '<div class="stat-item stat-degraded"><span class="stat-number">' . $degraded_count . '</span><span class="stat-label">Impaired</span></div>',
      ],
      'oos_stat' => [
        '#markup' => '<div class="stat-item stat-oos"><span class="stat-number">' . $oos_count . '</span><span class="stat-label">Offline</span></div>',
      ],
    ];

    // Review Queue section.
    $build['queue_heading'] = [
      '#markup' => '<h2 class="maintenance-section-heading">' . $this->t('Review Queue — Member Reports') . '</h2>',
    ];

    if (empty($queue_nodes)) {
      $build['queue_empty'] = [
        '#markup' => '<p class="maintenance-empty">' . $this->t('No tools awaiting staff review. All clear.') . '</p>',
      ];
    }
    else {
      $queue_rows = [];
      foreach ($queue_nodes as $node) {
        $log = $this->getLatestLog($node, 'inspection');
        $reporter = '';
        $reported_severity = '';
        $details_text = '';

        if ($log) {
          $owner = $log->getOwner();
          $reporter = $owner ? $owner->getDisplayName() : $this->t('Unknown');
          $reported_term = $log->getReportedStatus();
          $reported_severity = $reported_term ? $reported_term->label() : '';
          $details_text = $log->getDetails() ?: $log->getSummary();
          if (preg_match('/^Member Report \([^)]+\): (.+)$/s', (string) $details_text, $m)) {
            $details_text = $m[1];
          }
        }

        $queue_rows[] = [
          'class' => ['maintenance-row-concern'],
          'data' => [
            ['data' => Link::fromTextAndUrl($node->getTitle(), Url::fromRoute('entity.node.canonical', ['node' => $node->id()]))],
            ['data' => $this->getAreaLabel($node)],
            ['data' => $log ? $this->formatIssueType($log->getSummary()) : ''],
            ['data' => $reporter],
            ['data' => $this->statusBadge((string) $reported_severity)],
            ['data' => Markup::create('<span class="detail-text">' . nl2br(htmlspecialchars(mb_strimwidth((string) $details_text, 0, 120, '…'))) . '</span>')],
            ['data' => $this->getTimeInStatus($node)],
            ['data' => Link::fromTextAndUrl($this->t('Review →'), Url::fromRoute('asset_status.quick_status_update', ['node' => $node->id()]))],
          ],
        ];
      }

      $build['queue_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Tool'),
          $this->t('Area'),
          $this->t('Issue Type'),
          $this->t('Reporter'),
          $this->t("Member's Assessment"),
          $this->t('Details'),
          $this->t('Waiting'),
          $this->t('Actions'),
        ],
        '#rows' => $queue_rows,
        '#attributes' => ['class' => ['maintenance-table', 'maintenance-table-queue']],
      ];
    }

    // Currently Offline / Impaired section.
    $build['down_heading'] = [
      '#markup' => '<h2 class="maintenance-section-heading">' . $this->t('Currently Offline or Impaired') . '</h2>',
    ];

    if (empty($down_nodes)) {
      $build['down_empty'] = [
        '#markup' => '<p class="maintenance-empty">' . $this->t('No tools currently offline or degraded.') . '</p>',
      ];
    }
    else {
      $down_rows = [];
      foreach ($down_nodes as $node) {
        $current_term = $node->get('field_item_status')->entity;
        $status_label = $current_term ? $current_term->label() : '';
        $log = $this->getLatestLog($node, 'status_change');
        $last_note = $log ? ($log->getDetails() ?: $log->getSummary()) : '';
        $hard_down = ['Out of Service', 'Gone', 'Offline for Maintenance'];
        $row_class = in_array($status_label, $hard_down, TRUE) ? 'maintenance-row-oos' : 'maintenance-row-degraded';

        $down_rows[] = [
          'class' => [$row_class],
          'data' => [
            ['data' => Link::fromTextAndUrl($node->getTitle(), Url::fromRoute('entity.node.canonical', ['node' => $node->id()]))],
            ['data' => $this->getAreaLabel($node)],
            ['data' => $this->statusBadge($status_label)],
            ['data' => $this->getTimeInStatus($node)],
            ['data' => Markup::create('<span class="detail-text">' . htmlspecialchars(mb_strimwidth((string) $last_note, 0, 100, '…')) . '</span>')],
            ['data' => Link::fromTextAndUrl($this->t('Update →'), Url::fromRoute('asset_status.quick_status_update', ['node' => $node->id()]))],
          ],
        ];
      }

      $build['down_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Tool'),
          $this->t('Area'),
          $this->t('Status'),
          $this->t('Duration'),
          $this->t('Last Note'),
          $this->t('Actions'),
        ],
        '#rows' => $down_rows,
        '#attributes' => ['class' => ['maintenance-table', 'maintenance-table-down']],
      ];
    }

    $build['#attached'] = ['library' => ['asset_status/maintenance_dashboard']];
    $build['#cache'] = [
      'tags' => ['node_list', 'asset_log_entry_list'],
      'max-age' => 60,
    ];

    return $build;
  }

  /**
   * Returns published item nodes with field_item_status in the given terms.
   */
  private function getNodesByStatus(array $terms): array {
    if (empty($terms)) {
      return [];
    }
    $term_ids = array_map(fn($t) => (int) $t->id(), $terms);
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'item')
      ->condition('status', 1)
      ->condition('field_item_status', $term_ids, 'IN')
      ->sort('changed', 'DESC')
      ->accessCheck(FALSE)
      ->execute();
    return $nids ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];
  }

  /**
   * Returns the most recent log entry of a given bundle for a node.
   */
  private function getLatestLog(NodeInterface $node, string $type): ?object {
    $storage = $this->entityTypeManager->getStorage('asset_log_entry');
    $ids = $storage->getQuery()
      ->condition('asset', $node->id())
      ->condition('type', $type)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  /**
   * Returns a human-readable "time since status change" string.
   */
  private function getTimeInStatus(NodeInterface $node): string {
    $log = $this->getLatestLog($node, 'status_change');
    $since = $log ? $log->getCreatedTime() : $node->getChangedTime();
    $seconds = \Drupal::time()->getRequestTime() - $since;

    if ($seconds < 3600) {
      return (string) $this->t('@m min', ['@m' => max(1, (int) round($seconds / 60))]);
    }
    if ($seconds < 86400) {
      return (string) $this->t('@h hr', ['@h' => (int) round($seconds / 3600)]);
    }
    if ($seconds < 86400 * 30) {
      return (string) $this->t('@d days', ['@d' => (int) round($seconds / 86400)]);
    }
    return (string) $this->t('@w wk', ['@w' => (int) round($seconds / 604800)]);
  }

  /**
   * Returns the area of interest label for a node.
   */
  private function getAreaLabel(NodeInterface $node): string {
    if ($node->hasField('field_item_area_interest') && !$node->get('field_item_area_interest')->isEmpty()) {
      $term = $node->get('field_item_area_interest')->entity;
      return $term ? $term->label() : '';
    }
    return '';
  }

  /**
   * Returns a colored status badge as safe Markup.
   */
  private function statusBadge(string $label): Markup {
    $class_map = [
      'Reported Concern' => 'badge-concern',
      'Degraded'         => 'badge-degraded',
      'Out of Service'   => 'badge-oos',
      'Operational'      => 'badge-ok',
    ];
    $css = $class_map[$label] ?? 'badge-unknown';
    return Markup::create('<span class="status-badge ' . $css . '">' . htmlspecialchars($label) . '</span>');
  }

  /**
   * Extracts a display-friendly issue type from a log summary string.
   */
  private function formatIssueType(string $summary): string {
    if (preg_match('/\(([^)]+)\)/', $summary, $m)) {
      return str_replace('_', ' ', $m[1]);
    }
    return $summary;
  }

  /**
   * Returns all item_status terms that represent maintenance-actionable offline
   * states, dynamically excluding administrative/non-maintenance labels.
   *
   * This avoids hardcoding environment-specific term names like "Offline for
   * Maintenance" vs "Degraded" and automatically picks up any future terms.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   */
  private function getOfflineTerms(): array {
    $all_terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'item_status']);
    return array_values(array_filter(
      $all_terms,
      fn($t) => !in_array($t->label(), self::EXCLUDED_FROM_OFFLINE, TRUE)
    ));
  }

  /**
   * Loads a taxonomy term from item_status vocabulary by label.
   */
  private function getTermByLabel(string $label): ?object {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'item_status',
      'name' => $label,
    ]);
    return $terms ? reset($terms) : NULL;
  }

}
