<?php

declare(strict_types=1);

namespace Drupal\asset_status\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Administrative listing for asset log entries.
 */
final class AssetLogEntryListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['summary'] = $this->t('Summary');
    $header['asset'] = $this->t('Asset');
    $header['bundle'] = $this->t('Type');
    $header['reported_status'] = $this->t('Reported status');
    $header['confirmed_status'] = $this->t('Confirmed status');
    $header['changed'] = $this->t('Updated');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\asset_status\Entity\AssetLogEntryInterface $entity */
    $row['summary'] = Link::fromTextAndUrl($entity->label(), $entity->toUrl());
    $asset = $entity->getAsset();
    $row['asset'] = $asset ? Link::createFromRoute($asset->label(), 'entity.node.canonical', ['node' => $asset->id()]) : $this->t('Unknown');
    $row['bundle'] = $entity->bundle();

    $reported_status = $entity->getReportedStatus();
    $row['reported_status'] = $reported_status ? $reported_status->label() : $this->t('n/a');

    $confirmed_status = $entity->getConfirmedStatus();
    $row['confirmed_status'] = $confirmed_status ? $confirmed_status->label() : $this->t('n/a');

    $row['changed'] = $this->dateFormatter->format($entity->getChangedTime(), 'short');

    return $row + parent::buildRow($entity);
  }

}
