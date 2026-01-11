<?php

declare(strict_types=1);

namespace Drupal\asset_status\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\NodeInterface;

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
   * Constructs a new AssetStatusBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
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
      $container->get('current_route_match')
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
      ->accessCheck(TRUE)
      ->execute();

    $latest_message = '';
    if (!empty($logs)) {
      $log_id = reset($logs);
      /** @var \Drupal\asset_status\Entity\AssetLogEntryInterface $log_entry */
      $log_entry = $this->entityTypeManager->getStorage('asset_log_entry')->load($log_id);
      if ($log_entry) {
        // We prefer the 'details' field if populated, otherwise the summary.
        // But for status display, usually 'details' contains the "Waiting for parts" info.
        $latest_message = $log_entry->getDetails();
      }
    }

    // Define color classes based on status label.
    // Normalized to match legacy and new terms.
    $class_map = [
      'Operational' => 'status-operational',
      'Active' => 'status-operational', // Legacy
      'Degraded' => 'status-degraded',
      'Maintenance' => 'status-degraded', // Legacy
      'Out of Service' => 'status-out-of-service',
      'Gone' => 'status-out-of-service', // Legacy
      'Setup / Training Only' => 'status-setup',
      'Setup' => 'status-setup', // Legacy
      'Storage' => 'status-storage',
    ];

    $css_class = $class_map[$status_label] ?? 'status-unknown';

    // Generate history URL.
    $history_url = Url::fromRoute('entity.node.asset_status.history', ['node' => $node->id()])->toString();

    // Build the render array.
    $build = [
      '#theme' => 'asset_status_block',
      '#status_label' => $status_label,
      '#status_class' => $css_class,
      '#message' => $latest_message,
      '#history_url' => $history_url,
      '#attached' => [
        'library' => [
          'asset_status/asset_status_block',
        ],
      ],
      '#cache' => [
        'tags' => array_merge($node->getCacheTags(), ['asset_log_entry_list']),
      ],
    ];

    return $build;
  }

}
