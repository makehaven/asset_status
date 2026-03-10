<?php

declare(strict_types=1);

namespace Drupal\asset_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public-facing tool status board.
 *
 * Shows all equipment with colored status indicators, sortable columns,
 * and a filter by status. Uses direct DB queries (not entity loading) so
 * rendering 400+ tools stays fast.
 *
 * Status severity tiers (worst → best, for default sort):
 *   Tier 0 – Gone / Out of Service
 *   Tier 1 – Offline for Maintenance / Offline - Initial Setup / Degraded
 *   Tier 2 – Reported Concern
 *   Tier 3 – Storage / Setup / Training Only
 *   Tier 99 – Operational (shown last by default)
 */
final class ToolStatusBoardController extends ControllerBase {

  /**
   * Status → severity tier (lower = worse).
   */
  private const SEVERITY = [
    'Gone'                     => 0,
    'Out of Service'           => 0,
    'Offline for Maintenance'  => 1,
    'Offline - Initial Setup'  => 1,
    'Degraded'                 => 1,
    'Reported Concern'         => 2,
    'Storage'                  => 3,
    'Setup / Training Only'    => 3,
    'Operational'              => 99,
    'Active'                   => 99,
  ];

  /**
   * Status → CSS modifier for the light dot.
   */
  private const LIGHT_CLASS = [
    'Operational'              => 'light-green',
    'Active'                   => 'light-green',
    'Reported Concern'         => 'light-amber',
    'Degraded'                 => 'light-amber',
    'Offline for Maintenance'  => 'light-red',
    'Offline - Initial Setup'  => 'light-red',
    'Gone'                     => 'light-red',
    'Out of Service'           => 'light-red',
    'Storage'                  => 'light-grey',
    'Setup / Training Only'    => 'light-grey',
  ];

  public function __construct(
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Renders the status board.
   */
  public function board(Request $request): array {
    $status_filter = $request->query->get('status', '');
    $sort_by       = $request->query->get('sort_by', 'severity');
    $sort_order    = $request->query->get('sort_order', 'asc');

    // Load all item_status terms for the filter dropdown.
    $status_terms = $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid', 'name'])
      ->condition('t.vid', 'item_status')
      ->orderBy('t.name')
      ->execute()
      ->fetchAllKeyed();

    // Main query: item nodes with status label and area (LEFT JOIN so tools
    // without an area still appear).
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_item_status', 's', 's.entity_id = n.nid AND s.deleted = 0');
    $query->join('taxonomy_term_field_data', 'st', 'st.tid = s.field_item_status_target_id');
    $query->leftJoin('node__field_item_area_interest', 'ai', 'ai.entity_id = n.nid AND ai.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'at', 'at.tid = ai.field_item_area_interest_target_id');
    $query->fields('n', ['nid', 'title', 'changed']);
    $query->addField('st', 'name', 'status_label');
    $query->addField('s', 'field_item_status_target_id', 'status_tid');
    $query->addField('at', 'name', 'area_label');
    $query->condition('n.type', 'item');
    $query->condition('n.status', 1);
    if ($status_filter !== '') {
      $query->condition('s.field_item_status_target_id', (int) $status_filter);
    }

    $rows = $query->execute()->fetchAll();

    if (empty($rows)) {
      return [
        '#markup' => '<p>' . $this->t('No tools found.') . '</p>',
        '#attached' => ['library' => ['asset_status/status_board']],
      ];
    }

    // Batch-load the last status_change timestamp per tool (one query).
    $nids = array_column($rows, 'nid');
    $since_map = $this->loadLastStatusChangeTimes($nids);

    // Augment rows with severity and seconds-in-status.
    $now = \Drupal::time()->getRequestTime();
    foreach ($rows as $row) {
      $row->severity         = self::SEVERITY[$row->status_label] ?? 50;
      $since                 = $since_map[$row->nid] ?? $row->changed;
      $row->seconds_offline  = $now - (int) $since;
    }

    // Sort.
    $rows = $this->sortRows($rows, $sort_by, $sort_order);

    // Build the render array.
    $build = [];

    // Filter bar.
    $build['filter'] = $this->buildFilterBar($status_filter, $status_terms, count($rows));

    // Summary stats strip (only when showing all statuses).
    if ($status_filter === '') {
      $build['stats'] = $this->buildStatsStrip($rows);
    }

    // Table.
    $build['table'] = $this->buildTable($rows, $sort_by, $sort_order, $status_filter);

    $build['#attached'] = ['library' => ['asset_status/status_board']];
    $build['#cache'] = [
      'contexts' => ['url.query_args'],
      'tags'     => ['node_list', 'asset_log_entry_list'],
      'max-age'  => 120,
    ];

    return $build;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Loads the most-recent status_change log timestamp per node, in one query.
   *
   * @param int[] $nids
   *
   * @return array<int, int>  Keyed by nid, value is Unix timestamp.
   */
  private function loadLastStatusChangeTimes(array $nids): array {
    if (empty($nids)) {
      return [];
    }
    return $this->database->select('asset_log_entry', 'l')
      ->fields('l', ['asset'])
      ->addExpression('MAX(l.created)', 'last_change')
      ->condition('l.type', 'status_change')
      ->condition('l.asset', $nids, 'IN')
      ->groupBy('l.asset')
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Sorts the rows array by the requested column.
   */
  private function sortRows(array $rows, string $sort_by, string $sort_order): array {
    usort($rows, function ($a, $b) use ($sort_by, $sort_order): int {
      $cmp = match ($sort_by) {
        'name'    => strcasecmp($a->title, $b->title),
        'area'    => strcasecmp((string) $a->area_label, (string) $b->area_label),
        'status'  => $a->severity <=> $b->severity ?: $b->seconds_offline <=> $a->seconds_offline,
        'since'   => $b->seconds_offline <=> $a->seconds_offline,
        default   => $a->severity <=> $b->severity ?: $b->seconds_offline <=> $a->seconds_offline,
      };
      return $sort_order === 'desc' ? -$cmp : $cmp;
    });
    return $rows;
  }

  /**
   * Returns the filter bar markup.
   */
  private function buildFilterBar(string $current_filter, array $terms, int $count): array {
    $options_html = '<option value=""' . ($current_filter === '' ? ' selected' : '') . '>' . $this->t('All statuses') . '</option>';
    foreach ($terms as $tid => $label) {
      $selected = ((string) $current_filter === (string) $tid) ? ' selected' : '';
      $options_html .= '<option value="' . $tid . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }

    return [
      '#type'  => 'html_tag',
      '#tag'   => 'div',
      '#attributes' => ['class' => ['status-board-filter']],
      '#value' => '<form method="get" class="status-filter-form">'
        . '<label for="status-select">' . $this->t('Filter by status:') . '</label> '
        . '<select id="status-select" name="status" onchange="this.form.submit()">'
        . $options_html
        . '</select>'
        . '<noscript><button type="submit">' . $this->t('Apply') . '</button></noscript>'
        . '<span class="filter-count">' . $this->t('@n tools', ['@n' => $count]) . '</span>'
        . '</form>',
    ];
  }

  /**
   * Returns a compact stats strip (counts per status tier).
   */
  private function buildStatsStrip(array $rows): array {
    $counts = ['light-green' => 0, 'light-amber' => 0, 'light-red' => 0, 'light-grey' => 0];
    foreach ($rows as $row) {
      $class = self::LIGHT_CLASS[$row->status_label] ?? 'light-grey';
      $counts[$class]++;
    }

    $html = '<div class="status-board-stats">';
    $html .= '<span class="stat-dot-item"><span class="status-light light-green"></span>' . $counts['light-green'] . ' ' . $this->t('Operational') . '</span>';
    $html .= '<span class="stat-dot-item"><span class="status-light light-amber"></span>' . $counts['light-amber'] . ' ' . $this->t('Concern / Degraded') . '</span>';
    $html .= '<span class="stat-dot-item"><span class="status-light light-red"></span>' . $counts['light-red'] . ' ' . $this->t('Offline') . '</span>';
    if ($counts['light-grey'] > 0) {
      $html .= '<span class="stat-dot-item"><span class="status-light light-grey"></span>' . $counts['light-grey'] . ' ' . $this->t('Storage / Setup') . '</span>';
    }
    $html .= '</div>';

    return ['#markup' => Markup::create($html)];
  }

  /**
   * Builds the sortable table render array.
   */
  private function buildTable(array $rows, string $sort_by, string $sort_order, string $status_filter): array {
    $header = [
      ['label' => '', 'field' => '', 'class' => ['col-light']],
      ['label' => $this->t('Tool'), 'field' => 'name'],
      ['label' => $this->t('Area'), 'field' => 'area'],
      ['label' => $this->t('Status'), 'field' => 'status'],
      ['label' => $this->t('Time in Status'), 'field' => 'since'],
    ];

    $header_row = [];
    foreach ($header as $col) {
      if (empty($col['field'])) {
        $header_row[] = ['data' => '', 'class' => $col['class'] ?? []];
        continue;
      }
      $is_active   = $sort_by === $col['field'];
      $next_order  = ($is_active && $sort_order === 'asc') ? 'desc' : 'asc';
      $arrow       = $is_active ? ($sort_order === 'asc' ? ' ↑' : ' ↓') : '';
      $params      = ['sort_by' => $col['field'], 'sort_order' => $next_order];
      if ($status_filter !== '') {
        $params['status'] = $status_filter;
      }
      $url = Url::fromRoute('asset_status.tool_status_board', [], ['query' => $params]);
      $header_row[] = [
        'data'  => Link::fromTextAndUrl(Markup::create($col['label'] . $arrow), $url),
        'class' => $is_active ? ['is-active'] : [],
      ];
    }

    $table_rows = [];
    foreach ($rows as $row) {
      $light_class = self::LIGHT_CLASS[$row->status_label] ?? 'light-grey';
      $severity    = self::SEVERITY[$row->status_label] ?? 50;
      $is_offline  = $severity <= 2;
      $time_str    = $is_offline ? $this->formatSeconds((int) $row->seconds_offline) : '—';

      $tool_link = Link::fromTextAndUrl($row->title, Url::fromRoute('entity.node.canonical', ['node' => $row->nid]));

      $table_rows[] = [
        'class' => $is_offline ? ['row-offline'] : [],
        'data'  => [
          ['data' => Markup::create('<span class="status-light ' . $light_class . '" title="' . htmlspecialchars($row->status_label) . '"></span>'), 'class' => ['col-light']],
          ['data' => $tool_link],
          ['data' => $row->area_label ?: '—'],
          ['data' => Markup::create('<span class="status-badge-sm status-badge-' . $light_class . '">' . htmlspecialchars($row->status_label) . '</span>')],
          ['data' => Markup::create('<span class="time-in-status' . ($is_offline ? ' time-offline' : '') . '">' . $time_str . '</span>')],
        ],
      ];
    }

    return [
      '#type'       => 'table',
      '#header'     => $header_row,
      '#rows'       => $table_rows,
      '#empty'      => $this->t('No tools match the selected filter.'),
      '#attributes' => ['class' => ['tool-status-board-table']],
    ];
  }

  /**
   * Converts a duration in seconds to a compact human-readable string.
   */
  private function formatSeconds(int $seconds): string {
    if ($seconds < 3600) {
      return $this->t('@m min', ['@m' => max(1, (int) round($seconds / 60))]);
    }
    if ($seconds < 86400) {
      return $this->t('@h hr', ['@h' => (int) round($seconds / 3600)]);
    }
    if ($seconds < 86400 * 30) {
      return $this->t('@d days', ['@d' => (int) round($seconds / 86400)]);
    }
    return $this->t('@w wk', ['@w' => (int) round($seconds / 604800)]);
  }

}
