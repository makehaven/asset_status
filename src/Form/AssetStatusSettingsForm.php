<?php

declare(strict_types=1);

namespace Drupal\asset_status\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for asset status module settings.
 */
final class AssetStatusSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['asset_status.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'asset_status_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('asset_status.settings');

    $form['history_access_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Maintenance history visibility'),
      '#description' => $this->t('Choose who can view per-tool maintenance history tabs and pages.'),
      '#options' => [
        'authenticated' => $this->t('All authenticated users (members, volunteers, staff)'),
        'permission' => $this->t('Only users with asset-status permissions'),
      ],
      '#default_value' => $config->get('history_access_mode') ?: 'authenticated',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('asset_status.settings')
      ->set('history_access_mode', $form_state->getValue('history_access_mode'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
