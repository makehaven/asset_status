<?php

declare(strict_types=1);

namespace Drupal\asset_status\Controller;

use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Shared sibling-navigation header for asset status pages.
 */
trait AssetStatusNavTrait {

  /**
   * Builds a sibling nav header linking the main asset status pages.
   *
   * @param string $active
   *   Which link is the current page: 'board', 'queue', or 'logs'.
   */
  protected function buildAssetStatusNav(string $active): array {
    $links = [
      'board' => [
        'title' => $this->t('Equipment Status Board'),
        'url'   => Url::fromRoute('asset_status.tool_status_board'),
      ],
      'queue' => [
        'title' => $this->t('Maintenance Queue'),
        'url'   => Url::fromRoute('asset_status.maintenance_dashboard'),
      ],
      'logs' => [
        'title' => $this->t('All Log Entries'),
        'url'   => Url::fromRoute('entity.asset_log_entry.collection'),
      ],
    ];

    $items = [];
    foreach ($links as $key => $info) {
      if (!$info['url']->access()) {
        continue;
      }
      if ($key === $active) {
        $items[] = [
          '#markup' => '<strong>' . $info['title'] . '</strong>',
          '#wrapper_attributes' => ['class' => ['is-active']],
        ];
      }
      else {
        $items[] = Link::fromTextAndUrl($info['title'], $info['url'])->toRenderable();
      }
    }

    return [
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#items' => $items,
      '#attributes' => ['class' => ['asset-status-nav']],
      '#wrapper_attributes' => ['class' => ['asset-status-nav-wrapper']],
      '#attached' => ['library' => ['asset_status/nav']],
      '#cache' => ['contexts' => ['user.permissions']],
    ];
  }

}
