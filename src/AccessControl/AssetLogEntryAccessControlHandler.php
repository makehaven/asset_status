<?php

declare(strict_types=1);

namespace Drupal\asset_status\AccessControl;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Restricts access to asset log entries via module permissions.
 */
final class AssetLogEntryAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    switch ($operation) {
      case 'view':
        return $this->accessForPermission($account, 'review asset status reports');
      case 'update':
      case 'edit':
        return $this->accessForPermission($account, 'log asset maintenance events');
      case 'delete':
        return AccessResult::allowedIf($account->hasPermission('administer asset log entries'));
    }

    return parent::checkAccess($entity, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIf($account->hasPermission('administer asset log entries')
      || $account->hasPermission('log asset maintenance events'));
  }

  /**
   * Returns an access result for the given permission plus administrative override.
   */
  private function accessForPermission(AccountInterface $account, string $permission): AccessResult {
    return AccessResult::allowedIf($account->hasPermission($permission)
      || $account->hasPermission('administer asset log entries'));
  }

}
