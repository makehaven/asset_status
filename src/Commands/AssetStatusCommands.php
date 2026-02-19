<?php

declare(strict_types=1);

namespace Drupal\asset_status\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for asset status operations.
 */
final class AssetStatusCommands extends DrushCommands {

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the commands service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Audits Slack channel routing coverage for item nodes.
   *
   * @command asset-status:slack-audit
   * @option limit Number of uncovered tools to show in detail.
   * @option show-covered Also print covered tool rows.
   * @usage drush asset-status:slack-audit
   * @usage drush asset-status:slack-audit --limit=100
   * @usage drush asset-status:slack-audit --show-covered=1
   */
  public function slackAudit(array $options = [
    'limit' => 50,
    'show-covered' => FALSE,
  ]): void {
    $limit = max(1, (int) ($options['limit'] ?? 50));
    $show_covered = !empty($options['show-covered']);

    $node_storage = $this->entityTypeManager->getStorage('node');

    $nids = $node_storage->getQuery()
      ->condition('type', 'item')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      $this->logger()->warning('No item nodes were found.');
      return;
    }

    $nodes = $node_storage->loadMultiple($nids);

    $covered_rows = [];
    $uncovered_rows = [];

    foreach ($nodes as $node) {
      $channel = '';
      $source = '';

      if ($node->hasField('field_item_slack_channel') && !$node->get('field_item_slack_channel')->isEmpty()) {
        $channel = trim((string) $node->get('field_item_slack_channel')->value);
        if ($channel !== '') {
          $source = 'item';
        }
      }

      if ($channel === '' && $node->hasField('field_item_area_interest') && !$node->get('field_item_area_interest')->isEmpty()) {
        $terms = $node->get('field_item_area_interest')->referencedEntities();
        foreach ($terms as $term) {
          if ($term->hasField('field_interest_slack_channel') && !$term->get('field_interest_slack_channel')->isEmpty()) {
            $term_channel = trim((string) $term->get('field_interest_slack_channel')->value);
            if ($term_channel !== '') {
              $channel = $term_channel;
              $source = 'area_interest';
              break;
            }
          }
        }
      }

      $row = [
        'nid' => (int) $node->id(),
        'label' => (string) $node->label(),
        'channel' => $channel !== '' ? $channel : '-',
        'source' => $source !== '' ? $source : '-',
      ];

      if ($channel === '') {
        $uncovered_rows[] = $row;
      }
      else {
        $covered_rows[] = $row;
      }
    }

    $total = count($nodes);
    $covered = count($covered_rows);
    $uncovered = count($uncovered_rows);
    $coverage_pct = $total > 0 ? round(($covered / $total) * 100, 1) : 0;

    $this->logger()->notice('Item nodes: {total}', ['total' => $total]);
    $this->logger()->notice('Covered by Slack routing: {covered} ({pct}%)', [
      'covered' => $covered,
      'pct' => $coverage_pct,
    ]);
    $this->logger()->notice('Missing Slack routing: {uncovered}', ['uncovered' => $uncovered]);

    if ($uncovered > 0) {
      $this->io()->title('Uncovered item nodes (sample)');
      $this->io()->table(
        ['nid', 'label', 'channel', 'source'],
        array_slice($uncovered_rows, 0, $limit)
      );
    }

    if ($show_covered && $covered > 0) {
      $this->io()->title('Covered item nodes (sample)');
      $this->io()->table(
        ['nid', 'label', 'channel', 'source'],
        array_slice($covered_rows, 0, $limit)
      );
    }
  }

}
