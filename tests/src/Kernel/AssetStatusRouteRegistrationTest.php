<?php

namespace Drupal\Tests\asset_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Form\UserPermissionsForm;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Verifies asset_status routes and menu links are registered.
 *
 * @group asset_status
 */
class AssetStatusRouteRegistrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'options',
    'taxonomy',
    'workflows',
    'asset_status',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'asset_status']);
  }

  /**
   * Tests that core entity routes for asset log entities exist.
   */
  public function testAssetStatusEntityRoutesExist(): void {
    $route_provider = $this->container->get('router.route_provider');

    $this->assertNotNull($route_provider->getRouteByName('entity.asset_log_entry.collection'));
    $this->assertNotNull($route_provider->getRouteByName('entity.asset_log_entry_type.collection'));
  }

  /**
   * Tests that all menu links provided by asset_status point to valid routes.
   */
  public function testAssetStatusMenuLinkRoutesExist(): void {
    $route_provider = $this->container->get('router.route_provider');
    $menu_link_manager = $this->container->get('plugin.manager.menu.link');

    $definitions = $menu_link_manager->getDefinitions();
    $missing_routes = [];

    foreach ($definitions as $plugin_id => $definition) {
      if (($definition['provider'] ?? '') !== 'asset_status') {
        continue;
      }

      $route_name = $definition['route_name'] ?? '';
      if ($route_name === '') {
        continue;
      }

      try {
        $route_provider->getRouteByName($route_name);
      }
      catch (RouteNotFoundException $e) {
        $missing_routes[] = $plugin_id . ' -> ' . $route_name;
      }
    }

    $this->assertSame([], $missing_routes, 'All asset_status menu links reference valid routes.');
  }

  /**
   * Tests that the permissions form renders with asset_status enabled.
   */
  public function testPermissionsFormRenders(): void {
    $build = $this->container
      ->get('form_builder')
      ->getForm(UserPermissionsForm::class);

    $output = (string) $this->container
      ->get('renderer')
      ->renderRoot($build);

    $this->assertStringContainsString('Administer asset log entries', $output);
  }

}
