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
 * and filters by status and area. Uses direct DB queries (not entity loading)
 * so rendering 400+ tools stays fast.
 *
 * Status severity tiers (worst → best, for default sort):
 *   Tier 0 – Offline for Maintenance / Offline - Initial Setup / Degraded
 *   Tier 1 – Reported Concern
 *   Tier 2 – Gone / Out of Service
 *   Tier 99 – Operational / Active
 *   Tier 100 – Storage / Setup / Training Only (shown last by default)
 *
 * "Gone" items are hidden by default; select the Gone filter to see them.
 */
final class ToolStatusBoardController extends ControllerBase {

  use AssetStatusNavTrait;

  /**
   * Status → severity tier (lower = worse).
   */
  private const SEVERITY = [
    'Offline for Maintenance'  => 0,
    'Offline - Initial Setup'  => 0,
    'Degraded'                 => 0,
    'Reported Concern'         => 1,
    'Gone'                     => 2,
    'Out of Service'           => 2,
    'Operational'              => 99,
    'Active'                   => 99,
    'Storage'                  => 100,
    'Setup / Training Only'    => 100,
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
    $area_filter   = $request->query->get('area', '');
    $sort_by       = $request->query->get('sort_by', 'severity');
    $sort_order    = $request->query->get('sort_order', 'asc');

    // Load all item_status terms for the status filter dropdown.
    $status_terms = $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid', 'name'])
      ->condition('t.vid', 'item_status')
      ->orderBy('t.name')
      ->execute()
      ->fetchAllKeyed();

    // Load area terms used on item nodes with parent/weight info for hierarchy.
    // Ordered by parent weight then child weight to preserve taxonomy tree order.
    $area_query = $this->database->select('node__field_item_area_interest', 'ai');
    $area_query->join('taxonomy_term_field_data', 'at', 'at.tid = ai.field_item_area_interest_target_id');
    $area_query->join('node_field_data', 'n', 'n.nid = ai.entity_id AND n.type = :type AND n.status = 1', [':type' => 'item']);
    $area_query->leftJoin('taxonomy_term__parent', 'tp', 'tp.entity_id = at.tid');
    $area_query->leftJoin('taxonomy_term_field_data', 'pt', 'pt.tid = tp.parent_target_id AND tp.parent_target_id > 0');
    $area_query->fields('at', ['tid', 'name', 'weight']);
    $area_query->addExpression('COALESCE(tp.parent_target_id, 0)', 'parent_tid');
    $area_query->addField('pt', 'name', 'parent_name');
    $area_query->addField('pt', 'weight', 'parent_weight');
    $area_query->condition('ai.deleted', 0);
    $area_query->distinct();
    $area_query->orderBy('pt.weight');
    $area_query->orderBy('at.weight');
    $area_terms = $area_query->execute()->fetchAll();

    // Main query: one row per item node. Area is handled via subquery filter
    // only — not selected — so each tool appears exactly once.
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_item_status', 's', 's.entity_id = n.nid AND s.deleted = 0');
    $query->join('taxonomy_term_field_data', 'st', 'st.tid = s.field_item_status_target_id');
    $query->fields('n', ['nid', 'title', 'changed']);
    $query->addField('st', 'name', 'status_label');
    $query->addField('s', 'field_item_status_target_id', 'status_tid');
    $query->condition('n.type', 'item');
    $query->condition('n.status', 1);

    if ($status_filter !== '') {
      $query->condition('s.field_item_status_target_id', (int) $status_filter);
    }
    else {
      // Exclude "Gone" by default — it's not actionable inventory.
      $query->condition('st.name', ['Gone'], 'NOT IN');
    }

    if ($area_filter !== '') {
      // EXISTS subquery: node has at least one matching area term.
      $area_sub = $this->database->select('node__field_item_area_interest', 'af');
      $area_sub->addField('af', 'entity_id');
      $area_sub->where('af.entity_id = n.nid');
      $area_sub->condition('af.field_item_area_interest_target_id', (int) $area_filter);
      $area_sub->condition('af.deleted', 0);
      $query->exists($area_sub);
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
      $row->severity        = self::SEVERITY[$row->status_label] ?? 50;
      $since                = $since_map[$row->nid] ?? $row->changed;
      $row->seconds_offline = $now - (int) $since;
    }

    // Sort.
    $rows = $this->sortRows($rows, $sort_by, $sort_order);

    // Build the render array.
    $build = [];

    $build['nav'] = $this->buildAssetStatusNav('board');

    $build['filter'] = $this->buildFilterBar($status_filter, $status_terms, $area_filter, $area_terms, count($rows));

    if ($status_filter === '' && $area_filter === '') {
      $build['stats'] = $this->buildStatsStrip($rows);
    }

    $build['table'] = $this->buildTable($rows, $sort_by, $sort_order, $status_filter, $area_filter);

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
    $query = $this->database->select('asset_log_entry', 'l');
    $query->fields('l', ['asset']);
    $query->addExpression('MAX(l.created)', 'last_change');
    $query->condition('l.type', 'status_change');
    $query->condition('l.asset', $nids, 'IN');
    $query->groupBy('l.asset');
    return $query->execute()->fetchAllKeyed();
  }

  /**
   * Sorts the rows array by the requested column.
   */
  private function sortRows(array $rows, string $sort_by, string $sort_order): array {
    usort($rows, function ($a, $b) use ($sort_by, $sort_order): int {
      $cmp = match ($sort_by) {
        'name'   => strcasecmp($a->title, $b->title),
        'status' => $a->severity <=> $b->severity ?: $b->seconds_offline <=> $a->seconds_offline,
        'since'  => $b->seconds_offline <=> $a->seconds_offline,
        default  => $a->severity <=> $b->severity ?: $b->seconds_offline <=> $a->seconds_offline,
      };
      return $sort_order === 'desc' ? -$cmp : $cmp;
    });
    return $rows;
  }

  /**
   * Returns the filter bar markup (status + area dropdowns).
   *
   * @param array $area_terms  Array of stdClass rows with tid/name/weight/parent_tid/parent_name/parent_weight.
   */
  private function buildFilterBar(
    string $status_filter,
    array $status_terms,
    string $area_filter,
    array $area_terms,
    int $count,
  ): array {
    // Status dropdown.
    $status_options = '<option value=""' . ($status_filter === '' ? ' selected' : '') . '>' . $this->t('All statuses') . '</option>';
    foreach ($status_terms as $tid => $label) {
      $selected = ((string) $status_filter === (string) $tid) ? ' selected' : '';
      $status_options .= '<option value="' . $tid . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }

    // Area dropdown — grouped by parent term for visual hierarchy.
    // Root terms (parent_tid == 0) become <optgroup> labels; children go inside.
    // Dedup by tid (DISTINCT on multi-join can occasionally repeat).
    $seen_tids = [];
    $groups    = [];  // parent_tid => ['label' => string, 'weight' => int, 'options' => [...]]
    $roots     = [];  // standalone root terms with no parent

    foreach ($area_terms as $row) {
      if (isset($seen_tids[$row->tid])) {
        continue;
      }
      $seen_tids[$row->tid] = TRUE;

      if ((int) $row->parent_tid === 0) {
        // Root term — will be a standalone option (may also appear as a group
        // label below if children reference it).
        $roots[$row->tid] = $row;
      }
      else {
        $ptid = (int) $row->parent_tid;
        if (!isset($groups[$ptid])) {
          $groups[$ptid] = [
            'label'   => $row->parent_name ?? '',
            'weight'  => (int) ($row->parent_weight ?? 0),
            'options' => [],
          ];
        }
        $groups[$ptid]['options'][] = $row;
      }
    }

    // Sort groups by parent weight.
    uasort($groups, fn($a, $b) => $a['weight'] <=> $b['weight']);

    $area_options = '<option value=""' . ($area_filter === '' ? ' selected' : '') . '>' . $this->t('All areas') . '</option>';

    // Render root terms that have NO children as standalone options first.
    foreach ($roots as $tid => $row) {
      if (isset($groups[$tid])) {
        continue; // has children — will appear as optgroup label instead
      }
      $selected = ((string) $area_filter === (string) $tid) ? ' selected' : '';
      $area_options .= '<option value="' . $tid . '"' . $selected . '>' . htmlspecialchars($row->name) . '</option>';
    }

    // Render groups.
    foreach ($groups as $ptid => $group) {
      $area_options .= '<optgroup label="' . htmlspecialchars($group['label']) . '">';
      foreach ($group['options'] as $row) {
        $selected = ((string) $area_filter === (string) $row->tid) ? ' selected' : '';
        // Strip leading "- " from display name since optgroup provides context.
        $label = ltrim($row->name, '- ');
        $area_options .= '<option value="' . $row->tid . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
      }
      $area_options .= '</optgroup>';
    }

    $html = '<div class="status-board-filter">'
      . '<form method="get" class="status-filter-form">'
      . '<label for="status-select">' . $this->t('Status:') . '</label> '
      . '<select id="status-select" name="status" onchange="this.form.submit()">' . $status_options . '</select>'
      . ' <label for="area-select">' . $this->t('Area:') . '</label> '
      . '<select id="area-select" name="area" onchange="this.form.submit()">' . $area_options . '</select>'
      . '<noscript><button type="submit">' . $this->t('Apply') . '</button></noscript>'
      . '<span class="filter-count">' . $this->t('@n tools', ['@n' => $count]) . '</span>'
      . '</form>'
      . '</div>';

    return ['#markup' => Markup::create($html)];
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
  private function buildTable(array $rows, string $sort_by, string $sort_order, string $status_filter, string $area_filter): array {
    $header = [
      ['label' => '', 'field' => '', 'class' => ['col-light']],
      ['label' => $this->t('Tool'), 'field' => 'name'],
      ['label' => $this->t('Status'), 'field' => 'status'],
      ['label' => $this->t('Time in Status'), 'field' => 'since'],
    ];

    $header_row = [];
    foreach ($header as $col) {
      if (empty($col['field'])) {
        $header_row[] = ['data' => '', 'class' => $col['class'] ?? []];
        continue;
      }
      $is_active  = $sort_by === $col['field'];
      $next_order = ($is_active && $sort_order === 'asc') ? 'desc' : 'asc';
      $arrow      = $is_active ? ($sort_order === 'asc' ? ' ↑' : ' ↓') : '';
      $params     = ['sort_by' => $col['field'], 'sort_order' => $next_order];
      if ($status_filter !== '') {
        $params['status'] = $status_filter;
      }
      if ($area_filter !== '') {
        $params['area'] = $area_filter;
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
  private function formatSeconds(int $seconds): string|\Drupal\Core\StringTranslation\TranslatableMarkup {
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
