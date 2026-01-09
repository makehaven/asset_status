<?php

declare(strict_types=1);

namespace Drupal\asset_status\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Persists structured log entries whenever an asset status changes.
 */
final class StatusChangeLogger {

  use StringTranslationTrait;

  /**
   * Asset log entry storage handler.
   */
  private EntityStorageInterface $assetLogStorage;

  /**
   * Active user account.
   */
  private AccountProxyInterface $currentUser;

  /**
   * Logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * Constructs the logger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->assetLogStorage = $entityTypeManager->getStorage('asset_log_entry');
    $this->currentUser = $currentUser;
    $this->logger = $loggerFactory->get('asset_status');
  }

  /**
   * Creates a log entry describing an asset status change.
   */
  public function handleNodeStatusChange(NodeInterface $asset, ?TermInterface $originalStatus, ?TermInterface $currentStatus, array $context = []): void {
    $isNew = (bool) ($context['is_new'] ?? FALSE);

    if ($isNew && !$currentStatus) {
      // Nothing to log until the tool has an initial status.
      return;
    }

    if (!$isNew && $originalStatus && $currentStatus && $originalStatus->id() === $currentStatus->id()) {
      return;
    }

    if (!$isNew && !$originalStatus && !$currentStatus) {
      return;
    }

    $bundle = $context['bundle'] ?? 'status_change';
    $summary = $this->buildSummary($isNew, $originalStatus, $currentStatus);
    $details = $context['details'] ?? $this->buildDefaultDetails($asset, $originalStatus, $currentStatus, $isNew);

    $values = [
      'type' => $bundle,
      'asset' => $asset->id(),
      'summary' => $summary,
      'details' => $details,
      'reported_status' => $currentStatus ? $currentStatus->id() : NULL,
      'confirmed_status' => $currentStatus ? $currentStatus->id() : NULL,
      'user_id' => $this->resolveActingUserId($context),
    ];

    try {
      $entry = $this->assetLogStorage->create($values);
      $entry->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to record asset status change for node @nid: @message', [
        '@nid' => $asset->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Builds a localized summary for the log entry.
   */
  private function buildSummary(bool $isNew, ?TermInterface $originalStatus, ?TermInterface $currentStatus): string {
    if ($isNew) {
      return (string) $this->t('Initial status set to @status', [
        '@status' => $this->formatStatusLabel($currentStatus),
      ]);
    }

    if ($originalStatus && $currentStatus) {
      return (string) $this->t('Status changed from @old to @new', [
        '@old' => $this->formatStatusLabel($originalStatus),
        '@new' => $this->formatStatusLabel($currentStatus),
      ]);
    }

    if ($originalStatus && !$currentStatus) {
      return (string) $this->t('Status cleared from @status', [
        '@status' => $this->formatStatusLabel($originalStatus),
      ]);
    }

    return (string) $this->t('Status set to @status', [
      '@status' => $this->formatStatusLabel($currentStatus),
    ]);
  }

  /**
   * Provides a default log entry body.
   */
  private function buildDefaultDetails(NodeInterface $asset, ?TermInterface $originalStatus, ?TermInterface $currentStatus, bool $isNew): string {
    $user = $this->currentUser->getDisplayName();

    if ($isNew) {
      return (string) $this->t('@user created @asset with starting status @status.', [
        '@user' => $user,
        '@asset' => $asset->label(),
        '@status' => $this->formatStatusLabel($currentStatus),
      ]);
    }

    $old = $this->formatStatusLabel($originalStatus);
    $new = $this->formatStatusLabel($currentStatus);

    if ($originalStatus && $currentStatus) {
      return (string) $this->t('@user updated @asset from @old to @new.', [
        '@user' => $user,
        '@asset' => $asset->label(),
        '@old' => $old,
        '@new' => $new,
      ]);
    }

    if ($originalStatus && !$currentStatus) {
      return (string) $this->t('@user cleared the status on @asset (was @old).', [
        '@user' => $user,
        '@asset' => $asset->label(),
        '@old' => $old,
      ]);
    }

    return (string) $this->t('@user set the status on @asset to @new.', [
      '@user' => $user,
      '@asset' => $asset->label(),
      '@new' => $new,
    ]);
  }

  /**
   * Ensures we always return a human-friendly label.
   */
  private function formatStatusLabel(?TermInterface $term): string {
    return $term ? $term->label() : (string) $this->t('Unspecified');
  }

  /**
   * Determines the acting user ID for the log entry.
   */
  private function resolveActingUserId(array $context): int {
    if (!empty($context['user_id']) && (int) $context['user_id'] > 0) {
      return (int) $context['user_id'];
    }

    $uid = (int) $this->currentUser->id();
    return $uid > 0 ? $uid : 1;
  }

}
