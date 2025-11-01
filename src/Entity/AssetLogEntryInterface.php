<?php

declare(strict_types=1);

namespace Drupal\asset_status\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityOwnerInterface;
use Drupal\Core\Entity\RevisionLogInterface;

/**
 * Interface for asset log entries.
 */
interface AssetLogEntryInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface, RevisionLogInterface {

  /**
   * Gets the asset node related to the log entry.
   */
  public function getAsset(): ?\Drupal\node\NodeInterface;

  /**
   * Sets the related asset node.
   */
  public function setAsset(\Drupal\node\NodeInterface $asset): self;

  /**
   * Gets the member-reported status term.
   */
  public function getReportedStatus(): ?\Drupal\taxonomy\TermInterface;

  /**
   * Gets the staff-confirmed status term.
   */
  public function getConfirmedStatus(): ?\Drupal\taxonomy\TermInterface;

  /**
   * Sets the staff-confirmed status term.
   */
  public function setConfirmedStatus(?\Drupal\taxonomy\TermInterface $term): self;

  /**
   * Gets the short summary.
   */
  public function getSummary(): string;

  /**
   * Sets the short summary.
   */
  public function setSummary(string $summary): self;

  /**
   * Gets the detailed note, if any.
   */
  public function getDetails(): ?string;

  /**
   * Sets the detailed note.
   */
  public function setDetails(?string $details): self;

}
