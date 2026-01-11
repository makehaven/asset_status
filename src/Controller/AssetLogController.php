<?php

declare(strict_types=1);

namespace Drupal\asset_status\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for asset maintenance and history tabs.
 */
final class AssetLogController extends ControllerBase {

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
   * Provides the add maintenance log form.
   *
   * Pre-populates the asset reference.
   */
  public function addMaintenance(NodeInterface $node) {
    // Create a new maintenance log entry.
    $entity = $this->entityTypeManager->getStorage('asset_log_entry')->create([
      'type' => 'maintenance',
      'asset' => $node->id(),
    ]);

    // Return the form.
    return $this->entityFormBuilder->getForm($entity);
  }

  /**
   * Displays the asset log history for a node.
   */
  public function history(NodeInterface $node) {
    // Load logs referencing this asset.
    $storage = $this->entityTypeManager->getStorage('asset_log_entry');
    $query = $storage->getQuery()
      ->condition('asset', $node->id())
      ->sort('created', 'DESC')
      ->pager(20)
      ->accessCheck(TRUE);
    
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
      ],
      '#empty' => $this->t('No history found for this asset.'),
    ];

    /** @var \Drupal\asset_status\Entity\AssetLogEntryInterface $log */
    foreach ($logs as $log) {
      $build['#rows'][] = [
        ['data' => $this->dateFormatter()->format($log->getCreatedTime(), 'short')],
        ['data' => $log->getOwner()->getDisplayName()],
        ['data' => $log->bundle()],
        ['data' => [
          '#type' => 'link',
          '#title' => $log->label(),
          '#url' => $log->toUrl(),
        ]],
        ['data' => $log->getConfirmedStatus() ? $log->getConfirmedStatus()->label() : '-'],
      ];
    }

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Helper to access date formatter since we didn't inject it (lazy).
   */
  protected function dateFormatter() {
    return \Drupal::service('date.formatter');
  }

}
