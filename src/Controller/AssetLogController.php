<?php

declare(strict_types=1);

namespace Drupal\asset_status\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for asset maintenance and history tabs.
 */
final class AssetLogController extends ControllerBase {

  use AssetStatusNavTrait;

  /**
   * The entity form builder.
   *
   * @var \Drupal\Core\Entity\EntityFormBuilderInterface
   */
  protected $entityFormBuilder;

  /**
   * Constructs the controller.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFormBuilderInterface $entity_form_builder) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFormBuilder = $entity_form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.form_builder')
    );
  }

  /**
   * Provides the add maintenance log form with inline recent history.
   *
   * Pre-populates the asset reference.
   */
  public function addMaintenance(NodeInterface $node) {
    // Create a new maintenance log entry.
    $entity = $this->entityTypeManager->getStorage('asset_log_entry')->create([
      'type' => 'maintenance',
      'asset' => $node->id(),
    ]);

    $build = [];
    $build['form'] = $this->entityFormBuilder->getForm($entity);

    // Load and render the most recent log entries for this asset inline.
    $storage = $this->entityTypeManager->getStorage('asset_log_entry');
    $ids = $storage->getQuery()
      ->condition('asset', $node->id())
      ->sort('created', 'DESC')
      ->range(0, 5)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($ids)) {
      $logs = $storage->loadMultiple($ids);

      $rows = [];
      /** @var \Drupal\asset_status\Entity\AssetLogEntryInterface $log */
      foreach ($logs as $log) {
        $status_label = $log->getConfirmedStatus() ? $log->getConfirmedStatus()->label() : '—';
        $owner_name = $log->getOwner() ? $log->getOwner()->getDisplayName() : $this->t('Unknown');
        $details = $log->getDetails();
        $rows[] = [
          'data' => [
            ['data' => $this->dateFormatter()->format($log->getCreatedTime(), 'short')],
            ['data' => $owner_name],
            ['data' => $log->label()],
            ['data' => $status_label],
            ['data' => $details ? ['#markup' => nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8'))] : '—'],
          ],
        ];
      }

      $build['recent_history'] = [
        '#type' => 'details',
        '#title' => $this->t('Recent maintenance history (@count)', ['@count' => count($logs)]),
        '#open' => TRUE,
        '#weight' => 100,
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Date'),
            $this->t('By'),
            $this->t('Summary'),
            $this->t('Status'),
            $this->t('Details'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('No previous maintenance entries.'),
          '#attributes' => ['class' => ['asset-maintenance-history-table']],
        ],
      ];
    }

    return $build;
  }

  /**
   * Access callback for the maintenance form route.
   */
  public function accessMaintenance(NodeInterface $node, AccountInterface $account): AccessResult {
    if ($node->bundle() !== 'item') {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }

    $can_log = AccessResult::allowedIfHasPermissions($account, [
      'administer asset log entries',
      'log asset maintenance events',
    ], 'OR')->cachePerPermissions();

    return $node->access('view', $account, TRUE)->andIf($can_log);
  }

  /**
   * Access callback for the maintenance history route.
   */
  public function accessHistory(NodeInterface $node, AccountInterface $account): AccessResult {
    if ($node->bundle() !== 'item') {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }

    $mode = (string) $this->config('asset_status.settings')->get('history_access_mode');
    if ($mode === 'permission') {
      $can_review = AccessResult::allowedIfHasPermissions($account, [
        'administer asset log entries',
        'review asset status reports',
        'log asset maintenance events',
      ], 'OR')->cachePerPermissions();
    }
    else {
      // Keep history collaborative for logged-in members/volunteers/staff.
      $can_review = AccessResult::allowedIf($account->isAuthenticated())->cachePerUser();
    }

    return $node->access('view', $account, TRUE)->andIf($can_review);
  }

  /**
   * Displays the asset log history for a node.
   */
  public function history(NodeInterface $node) {
    $page = [];
    $page['nav'] = $this->buildAssetStatusNav('');

    // Load logs referencing this asset.
    $storage = $this->entityTypeManager->getStorage('asset_log_entry');
    $query = $storage->getQuery()
      ->condition('asset', $node->id())
      ->sort('created', 'DESC')
      ->pager(20)
      ->accessCheck(FALSE);
    
    $ids = $query->execute();
    $logs = $storage->loadMultiple($ids);

    // Build the render array.
    $build = [
      '#type' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('User'),
        $this->t('Type'),
        $this->t('Summary'),
        $this->t('Status'),
        $this->t('Details'),
      ],
      '#empty' => $this->t('No history found for this asset.'),
    ];

    /** @var \Drupal\asset_status\Entity\AssetLogEntryInterface $log */
    foreach ($logs as $log) {
      $details = $log->getDetails();
      $build['#rows'][] = [
        ['data' => $this->dateFormatter()->format($log->getCreatedTime(), 'short')],
        ['data' => $log->getOwner() ? $log->getOwner()->getDisplayName() : $this->t('Unknown user')],
        ['data' => $log->bundle()],
        ['data' => $log->label()],
        ['data' => $log->getConfirmedStatus() ? $log->getConfirmedStatus()->label() : '-'],
        ['data' => $details ? ['#markup' => nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8'))] : '—'],
      ];
    }

    $build['pager'] = [
      '#type' => 'pager',
    ];

    $page['table'] = $build;
    return $page;
  }

  /**
   * Helper to access date formatter since we didn't inject it (lazy).
   */
  protected function dateFormatter() {
    return \Drupal::service('date.formatter');
  }

}
