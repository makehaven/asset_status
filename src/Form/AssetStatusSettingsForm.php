<?php

declare(strict_types=1);

namespace Drupal\asset_status\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

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

    $form['quick_links'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Quick links: <a href=":board">Equipment Status Board</a> &nbsp;|&nbsp; <a href=":dashboard">Maintenance Queue</a> &nbsp;|&nbsp; <a href=":log">Asset log entries</a>', [
        ':board'     => Url::fromRoute('asset_status.tool_status_board')->toString(),
        ':dashboard' => Url::fromRoute('asset_status.maintenance_dashboard')->toString(),
        ':log'       => Url::fromRoute('entity.asset_log_entry.collection')->toString(),
      ]),
    ];

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

    $form['stale'] = [
      '#type' => 'details',
      '#title' => $this->t('Stale status reminders'),
      '#open' => TRUE,
      '#description' => $this->t('A tool that stays non-operational with no new log entry for longer than its threshold gets a Slack reminder ("still true?") with the quick-update link, repeated until someone updates it. Any new log entry resets the clock. Preview what would be sent with <code>drush asset-status:stale</code>.'),
    ];
    $form['stale']['stale_nudge_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send stale-status reminders to Slack (once a day from cron)'),
      '#default_value' => (bool) ($config->get('stale_nudge_enabled') ?? TRUE),
    ];
    $form['stale']['stale_slack_channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Channel that receives every reminder'),
      '#description' => $this->t("Staff channel for every reminder. Reminders do not go to the tool's own member channel; that is used only if this is left empty."),
      '#default_value' => $config->get('stale_slack_channel'),
      '#maxlength' => 80,
    ];
    $form['stale']['stale_repeat_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days between repeat reminders for the same tool'),
      '#min' => 1,
      '#default_value' => $config->get('stale_repeat_days') ?: 7,
    ];
    $thresholds = (array) ($config->get('stale_days') ?? []);
    $form['stale']['stale_days'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Days in status before a tool counts as stale'),
      '#description' => $this->t('One per line, <code>Status label: days</code>. Statuses not listed are never reminded. Operational, Storage, Gone and Setup are never reminded regardless.'),
      '#rows' => 6,
      '#default_value' => implode("\n", array_map(static fn($k, $v) => $k . ': ' . $v, array_keys($thresholds), $thresholds)),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('asset_status.settings')
      ->set('history_access_mode', $form_state->getValue('history_access_mode'))
      ->set('stale_nudge_enabled', (bool) $form_state->getValue('stale_nudge_enabled'))
      ->set('stale_slack_channel', trim((string) $form_state->getValue('stale_slack_channel')))
      ->set('stale_repeat_days', max(1, (int) $form_state->getValue('stale_repeat_days')))
      ->set('stale_days', $this->parseThresholds((string) $form_state->getValue('stale_days')))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Turns "Status label: days" lines into a label => days map.
   */
  protected function parseThresholds(string $text): array {
    $map = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
      if (!preg_match('/^\s*(.+?)\s*:\s*(\d+)\s*$/', $line, $m)) {
        continue;
      }
      $map[$m[1]] = (int) $m[2];
    }
    return $map;
  }

}
