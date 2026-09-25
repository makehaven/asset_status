<?php

declare(strict_types=1);

namespace Drupal\asset_status\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\Core\Access\AccessManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\NodeInterface;
use Drupal\asset_status\Service\UnitManager;

/**
 * Provides an 'Asset Status' block with detailed log info.
 *
 * @Block(
 *   id = "asset_status_detail_block",
 *   admin_label = @Translation("Asset Status (with details)"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
class AssetStatusBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The access manager.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected $accessManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Multi-unit tool helper.
   *
   * @var \Drupal\asset_status\Service\UnitManager
   */
  protected $units;

  /**
   * Constructs a new AssetStatusBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match, AccessManagerInterface $access_manager, AccountProxyInterface $current_user, UnitManager $units) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->accessManager = $access_manager;
    $this->currentUser = $current_user;
    $this->units = $units;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('access_manager'),
      $container->get('current_user'),
      $container->get('asset_status.units')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');

    if (!$node instanceof NodeInterface || $node->bundle() !== 'item' || !$node->hasField('field_item_status')) {
      return [];
    }

    // A tool made of several machines shows its machines, not its own status.
    if ($this->units->hasUnits($node)) {
      return $this->buildUnitsRollup($node);
    }

    $status_field = $node->get('field_item_status');
    if ($status_field->isEmpty()) {
      return [];
    }

    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = $status_field->entity;
    $status_label = $term->label();

    // Fetch the latest log entry for this asset.
    $logs = $this->entityTypeManager->getStorage('asset_log_entry')
      ->getQuery()
      ->condition('asset', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    $latest_message = '';
    $since = NULL;
    $expected_back = NULL;
    $overdue = FALSE;
    if (!empty($logs)) {
      $log_id = reset($logs);
      /** @var \Drupal\asset_status\Entity\AssetLogEntryInterface $log_entry */
      $log_entry = $this->entityTypeManager->getStorage('asset_log_entry')->load($log_id);
      if ($log_entry) {
        // We prefer the 'details' field if populated, otherwise fallback to summary.
        $latest_message = $log_entry->getDetails() ?: $log_entry->getSummary();
        $since = $log_entry->getCreatedTime();
        $expected_back = $log_entry->getExpectedBack();
        $overdue = $expected_back !== NULL && strtotime($expected_back . ' 23:59:59') < \Drupal::time()->getRequestTime();
      }
    }

    // Define color classes based on status label.
    // Normalized to match legacy and new terms.
    $class_map = [
      'Operational' => 'status-operational',
    // Legacy.
      'Active' => 'status-operational',
      'Reported Concern' => 'status-reported-concern',
      'Degraded' => 'status-degraded',
    // Legacy.
      'Maintenance' => 'status-degraded',
      'Out of Service' => 'status-out-of-service',
    // Legacy.
      'Gone' => 'status-out-of-service',
      'Setup / Training Only' => 'status-setup',
    // Legacy.
      'Setup' => 'status-setup',
      'Storage' => 'status-storage',
    ];

    $css_class = $class_map[$status_label] ?? 'status-unknown';

    // Generate history URL.
    $history_url = NULL;
    $history_access = $this->accessManager->checkNamedRoute('entity.node.asset_status.history', [
      'node' => $node->id(),
    ], $this->currentUser, TRUE);
    if ($history_access->isAllowed()) {
      $history_url = Url::fromRoute('entity.node.asset_status.history', ['node' => $node->id()])->toString();
    }

    $non_operational_statuses = [
      'Degraded',
      'Maintenance',
      'Offline for Maintenance',
      'Out of Service',
      'Gone',
      'Reported Concern',
    ];
    $is_non_operational = in_array($status_label, $non_operational_statuses, TRUE);

    // "Since" and "expected back" are what turn a bare status into something a
    // member can act on: they answer "how long has this been true?" and "when
    // will it be usable again?". Only shown for non-operational statuses.
    $date_formatter = \Drupal::service('date.formatter');
    $since_text = ($is_non_operational && $since)
      ? (string) $date_formatter->format($since, 'custom', 'M j')
      : NULL;
    $expected_text = ($is_non_operational && $expected_back)
      ? (string) $date_formatter->format(strtotime($expected_back), 'custom', 'M j')
      : NULL;

    // Fallback message: when a tool is in a non-operational status but no
    // log entry exists yet (e.g. status set programmatically, legacy data,
    // or feedback page rendered before the report was filed), surface a
    // hint so the viewer isn't left wondering *why* it's degraded. Without
    // this the block shows just "Status: Degraded" with no context.
    if (empty($latest_message) && in_array($status_label, $non_operational_statuses, TRUE)) {
      $latest_message = (string) $this->t('Reason not yet recorded — check history or ask staff.');
    }

    // Generate a staff "Update Status & Log" URL for non-operational statuses.
    $staff_action_url = NULL;
    if (in_array($status_label, $non_operational_statuses)) {
      // Prefer the quick status update form (enforces logging); fall back to maintenance log.
      $quick_status_access = $this->accessManager->checkNamedRoute('asset_status.quick_status_update', [
        'node' => $node->id(),
      ], $this->currentUser, TRUE);
      if ($quick_status_access->isAllowed()) {
        $staff_action_url = Url::fromRoute('asset_status.quick_status_update', ['node' => $node->id()])->toString();
      }
      else {
        $log_access = $this->accessManager->checkNamedRoute('entity.node.asset_status.maintenance', [
          'node' => $node->id(),
        ], $this->currentUser, TRUE);
        if ($log_access->isAllowed()) {
          $staff_action_url = Url::fromRoute('entity.node.asset_status.maintenance', ['node' => $node->id()])->toString();
        }
      }
    }

    // Build the render array.
    $build = [
      '#theme' => 'asset_status_block',
      '#status_label' => $status_label,
      '#status_class' => $css_class,
      '#message' => $latest_message,
      '#since' => $since_text,
      '#expected_back' => $expected_text,
      '#overdue' => $overdue,
      '#history_url' => $history_url,
      '#staff_action_url' => $staff_action_url,
      '#parent' => $this->parentVariable($node),
      '#serial' => $this->units->isUnit($node) ? $this->units->serial($node) : NULL,
      '#attached' => [
        'library' => [
          'asset_status/asset_status_block',
        ],
      ],
      '#cache' => [
        'tags' => Cache::mergeTags($node->getCacheTags(), ['asset_log_entry_list']),
        'contexts' => Cache::mergeContexts(['user.permissions'], $history_access->getCacheContexts()),
        'max-age' => $history_access->getCacheMaxAge(),
      ],
    ];

    return $build;
  }

  /**
   * Link data for "Part of <tool>" on a unit page, NULL for everything else.
   */
  protected function parentVariable(NodeInterface $node): ?array {
    if (!$this->units->isUnit($node)) {
      return NULL;
    }
    $parent = $this->units->getParent($node);
    if (!$parent || !$parent->access('view')) {
      return NULL;
    }
    return [
      'title' => $parent->label(),
      'url' => $parent->toUrl()->toString(),
    ];
  }

  /**
   * The block for a tool with units: "1 of 2 available" plus one row per machine.
   *
   * The tool's own status field is deliberately ignored here — it is kept in
   * step by UnitManager::syncParentStatus() for code that reads it, but the
   * machines are the truth a member needs.
   */
  protected function buildUnitsRollup(NodeInterface $node): array {
    $summary = $this->units->summary($node);
    $date_formatter = \Drupal::service('date.formatter');
    $now = \Drupal::time()->getRequestTime();
    $can_update = $this->accessManager->checkNamedRoute('asset_status.quick_status_update', ['node' => $node->id()], $this->currentUser, TRUE);

    $class_map = [
      'Operational' => 'status-operational',
      'Reported Concern' => 'status-reported-concern',
      'Degraded' => 'status-degraded',
      'Offline for Maintenance' => 'status-out-of-service',
      'Out of Service' => 'status-out-of-service',
      'Storage' => 'status-storage',
    ];
    $usable_but_flagged = ['Reported Concern', 'Degraded'];

    $items = [];
    $tags = $node->getCacheTags();
    foreach ($summary['units'] as $unit) {
      $unit_node = $this->entityTypeManager->getStorage('node')->load($unit['nid']);
      if (!$unit_node) {
        continue;
      }
      $tags = Cache::mergeTags($tags, $unit_node->getCacheTags());
      $flagged = !$unit['usable'] || in_array($unit['status'], $usable_but_flagged, TRUE);
      $expected = $unit['expected_back'] ? strtotime($unit['expected_back']) : NULL;
      $items[] = [
        'title' => $unit['title'],
        'serial' => $unit['serial'],
        'url' => $unit_node->toUrl()->toString(),
        'status' => $unit['status'],
        'status_class' => $class_map[$unit['status']] ?? 'status-unknown',
        'usable' => $unit['usable'],
        'since' => ($flagged && $unit['since']) ? (string) $date_formatter->format($unit['since'], 'custom', 'M j') : NULL,
        'expected_back' => ($flagged && $expected) ? (string) $date_formatter->format($expected, 'custom', 'M j') : NULL,
        'overdue' => $expected !== NULL && $expected + 86399 < $now,
        'update_url' => $can_update->isAllowed()
          ? Url::fromRoute('asset_status.quick_status_update', ['node' => $unit['nid']])->toString()
          : NULL,
        'report_url' => Url::fromUserInput('/equipment/issue', [
          'query' => [
            'equipment_name' => $unit['title'] . ' (' . $node->label() . ')',
            'asset_nid' => $unit['nid'],
          ],
        ])->toString(),
      ];
    }

    if ($summary['total'] === 0) {
      $label = (string) $this->t('No machines in service');
      $class = 'status-out-of-service';
    }
    elseif ($summary['available'] === $summary['total']) {
      $label = (string) $this->formatPlural($summary['total'], '1 machine available', '@count of @count machines available');
      $class = 'status-operational';
    }
    elseif ($summary['available'] === 0) {
      $label = (string) $this->formatPlural($summary['total'], 'The machine is out of service', 'All @count machines out of service');
      $class = 'status-out-of-service';
    }
    else {
      $label = (string) $this->t('@available of @total machines available', [
        '@available' => $summary['available'],
        '@total' => $summary['total'],
      ]);
      $class = 'status-degraded';
    }

    return [
      '#theme' => 'asset_status_block',
      '#status_label' => $label,
      '#status_class' => $class,
      '#units' => $items,
      '#attached' => [
        'library' => ['asset_status/asset_status_block', 'asset_status/units'],
        'drupalSettings' => ['assetStatus' => ['unitsParentNid' => (int) $node->id()]],
      ],
      '#cache' => [
        'tags' => Cache::mergeTags($tags, ['asset_log_entry_list']),
        'contexts' => Cache::mergeContexts(['user.permissions'], $can_update->getCacheContexts()),
        'max-age' => $can_update->getCacheMaxAge(),
      ],
    ];
  }

}
