<?php

declare(strict_types=1);

namespace Drupal\asset_status\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Provides availability logic and lookups for asset statuses.
 */
final class AssetAvailability {

  /**
   * The taxonomy vocabulary ID for item status.
   */
  private const VOCABULARY_ID = 'item_status';

  /**
   * Statuses that imply the asset is usable by members.
   */
  private const USABLE_STATUSES = [
    'Operational',
    'Degraded',
  ];

  /**
   * Entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the availability service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Checks if a given status term implies the asset is usable by members.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The status term.
   *
   * @return bool
   *   TRUE if the asset is usable, FALSE otherwise.
   */
  public function isUsable(TermInterface $term): bool {
    // Ensure we are checking the correct vocabulary.
    if ($term->bundle() !== self::VOCABULARY_ID) {
      return FALSE;
    }

    return in_array($term->label(), self::USABLE_STATUSES, TRUE);
  }

  /**
   * Helper to retrieve the "Out of Service" term.
   *
   * Useful for automated reporting workflows.
   */
  public function getOutOfServiceTerm(): ?TermInterface {
    return $this->getTermByLabel('Out of Service');
  }

  /**
   * Helper to look up a status term by its label.
   */
  public function getTermByLabel(string $label): ?TermInterface {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => self::VOCABULARY_ID,
      'name' => $label,
    ]);

    return $terms ? reset($terms) : NULL;
  }

}
