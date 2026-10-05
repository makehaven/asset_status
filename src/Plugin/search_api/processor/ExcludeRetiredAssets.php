<?php

namespace Drupal\asset_status\Plugin\search_api\processor;

use Drupal\asset_status\Service\UnitManager;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * Keeps retired equipment (item status "Gone") out of a search index.
 *
 * A facilitator searched /search for "band saw" and got the Band Saw that left
 * the building (ledger #45783). Badge pages already hide
 * retired tools; site search did not. The tool page itself stays reachable by
 * direct URL — this only stops the node from being indexed.
 *
 * Indexing-time exclusion rather than a view filter: Search API deletes
 * rejected items from the server, so marking a tool Gone drops it from results
 * on the next index pass (the index tracks node saves), and restoring a status
 * brings it back the same way. "Retired" is UnitManager::RETIRED_STATUSES,
 * matched by term label, so the rule lives in one place for every consumer.
 */
#[SearchApiProcessor(
  id: 'asset_status_exclude_retired',
  label: new TranslatableMarkup('Exclude retired equipment'),
  description: new TranslatableMarkup('Exclude nodes whose item status is retired ("Gone") from being indexed.'),
  stages: [
    'alter_items' => 0,
  ],
)]
class ExcludeRetiredAssets extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    foreach ($index->getDatasources() as $datasource) {
      if ($datasource->getEntityTypeId() === 'node') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items) {
    /** @var \Drupal\search_api\Item\ItemInterface $item */
    foreach ($items as $item_id => $item) {
      try {
        $object = $item->getOriginalObject()->getValue();
      }
      catch (\Throwable $e) {
        continue;
      }
      if ($object instanceof NodeInterface && self::isRetired($object)) {
        unset($items[$item_id]);
      }
    }
  }

  /**
   * TRUE when the node's item status is one of the retired statuses.
   *
   * Reads the field directly (same rule as UnitManager::isRetired()) so the
   * processor needs no service wiring; a node without the field, or with no
   * status, is never retired.
   */
  public static function isRetired(NodeInterface $node): bool {
    if (!$node->hasField('field_item_status') || $node->get('field_item_status')->isEmpty()) {
      return FALSE;
    }
    $term = $node->get('field_item_status')->entity;
    return $term && in_array((string) $term->label(), UnitManager::RETIRED_STATUSES, TRUE);
  }

}
