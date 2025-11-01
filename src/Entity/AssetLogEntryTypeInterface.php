<?php

declare(strict_types=1);

namespace Drupal\asset_status\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Interface for asset log entry bundles.
 */
interface AssetLogEntryTypeInterface extends ConfigEntityInterface {

  /**
   * Gets the default status transition behavior for the bundle.
   */
  public function getDefaultWorkflowState(): ?string;

  /**
   * Sets the default workflow state identifier.
   */
  public function setDefaultWorkflowState(?string $state): self;

}
