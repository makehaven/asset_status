<?php

declare(strict_types=1);

namespace Drupal\asset_status;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists asset log entry bundles.
 */
final class AssetLogEntryTypeListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('Machine name');
    $header['default_workflow_state'] = $this->t('Default workflow state');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\asset_status\Entity\AssetLogEntryTypeInterface $entity */
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['default_workflow_state'] = $entity->getDefaultWorkflowState() ?: $this->t('None');
    return $row + parent::buildRow($entity);
  }

}
