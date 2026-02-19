<?php

declare(strict_types=1);

namespace Drupal\asset_status\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Administrative listing for asset log entries.
 */
final class AssetLogEntryListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a new AssetLogEntryListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, DateFormatterInterface $date_formatter) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['summary'] = $this->t('Summary');
    $header['asset'] = $this->t('Asset');
    $header['bundle'] = $this->t('Type');
    $header['user_id'] = $this->t('Logged by');
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
    $owner = $entity->getOwner();
    $row['user_id'] = $owner ? $owner->getDisplayName() : $this->t('Unknown user');

    $reported_status = $entity->getReportedStatus();
    $row['reported_status'] = $reported_status ? $reported_status->label() : $this->t('n/a');

    $confirmed_status = $entity->getConfirmedStatus();
    $row['confirmed_status'] = $confirmed_status ? $confirmed_status->label() : $this->t('n/a');

    $row['changed'] = $this->dateFormatter->format($entity->getChangedTime(), 'short');

    return $row + parent::buildRow($entity);
  }

}
