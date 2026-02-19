<?php

declare(strict_types=1);

namespace Drupal\asset_status\Plugin\WebformHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\asset_status\Service\AssetAvailability;

/**
 * Updates asset status based on member reports.
 *
 * @WebformHandler(
 *   id = "asset_status_updater",
 *   label = @Translation("Asset Status Updater"),
 *   category = @Translation("Action"),
 *   description = @Translation("Updates the referenced asset's status and logs the report."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
final class AssetStatusWebformHandler extends WebformHandlerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The asset availability service.
   *
   * @var \Drupal\asset_status\Service\AssetAvailability
   */
  protected $assetAvailability;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'asset_nid_data_key' => 'asset_nid',
      'asset_data_key' => 'asset',
      'asset_nid_query_key' => 'asset_nid',
      'issue_type_key' => 'issue_type',
      'description_key' => 'describe_what_is_wrong',
      'out_of_service_values' => "broken_nonfuctional\ntool_missing",
      'degraded_values' => "damaged_functional\nparts_missing\nsupplies_missing",
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return [
      '#settings' => [
        'keys' => [
          'title' => $this->t('Configured keys'),
          'value' => [
            '#markup' => $this->t('asset_nid: @asset_nid, asset: @asset, issue_type: @issue, description: @desc', [
              '@asset_nid' => $this->configuration['asset_nid_data_key'] ?? 'asset_nid',
              '@asset' => $this->configuration['asset_data_key'] ?? 'asset',
              '@issue' => $this->configuration['issue_type_key'] ?? 'issue_type',
              '@desc' => $this->configuration['description_key'] ?? 'describe_what_is_wrong',
            ]),
          ],
        ],
      ],
    ] + parent::getSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['element_keys'] = [
      '#type' => 'details',
      '#title' => $this->t('Element keys'),
      '#open' => TRUE,
    ];

    $form['element_keys']['asset_nid_data_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submission key: asset node ID'),
      '#default_value' => $this->configuration['asset_nid_data_key'],
      '#description' => $this->t('Submission data key that contains the asset node ID.'),
      '#required' => TRUE,
    ];
    $form['element_keys']['asset_data_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submission key: asset reference fallback'),
      '#default_value' => $this->configuration['asset_data_key'],
      '#description' => $this->t('Fallback submission key for asset reference values.'),
      '#required' => TRUE,
    ];
    $form['element_keys']['asset_nid_query_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Query key fallback for asset node ID'),
      '#default_value' => $this->configuration['asset_nid_query_key'],
      '#description' => $this->t('URL query parameter used as final fallback for asset lookup.'),
      '#required' => TRUE,
    ];
    $form['element_keys']['issue_type_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submission key: issue type'),
      '#default_value' => $this->configuration['issue_type_key'],
      '#required' => TRUE,
    ];
    $form['element_keys']['description_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Submission key: issue description'),
      '#default_value' => $this->configuration['description_key'],
      '#required' => TRUE,
    ];

    $form['issue_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Issue mapping'),
      '#open' => TRUE,
    ];
    $form['issue_mapping']['out_of_service_values'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Issue values mapped to Out of Service'),
      '#default_value' => $this->configuration['out_of_service_values'],
      '#description' => $this->t('One issue value per line.'),
      '#rows' => 4,
    ];
    $form['issue_mapping']['degraded_values'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Issue values mapped to Degraded'),
      '#default_value' => $this->configuration['degraded_values'],
      '#description' => $this->t('One issue value per line.'),
      '#rows' => 5,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->assetAvailability = $container->get('asset_status.availability');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = FALSE) {
    // 1. Identify the Asset.
    $nid = NULL;
    $data = $webform_submission->getData();
    $asset_nid_data_key = (string) $this->configuration['asset_nid_data_key'];
    $asset_data_key = (string) $this->configuration['asset_data_key'];
    $asset_nid_query_key = (string) $this->configuration['asset_nid_query_key'];
    $issue_type_key = (string) $this->configuration['issue_type_key'];
    $description_key = (string) $this->configuration['description_key'];

    // Try data field first (most reliable if configured).
    if (!empty($data[$asset_nid_data_key])) {
      $nid = $data[$asset_nid_data_key];
    }
    elseif (!empty($data[$asset_data_key])) {
      $nid = $data[$asset_data_key];
    }

    // Try current route (if form is on the node page).
    if (!$nid) {
      $route_node = \Drupal::routeMatch()->getParameter('node');
      if ($route_node instanceof \Drupal\node\NodeInterface) {
        $nid = $route_node->id();
      }
    }

    // Try URL parameter (legacy / fallback).
    if (!$nid) {
      $nid = \Drupal::request()->query->get($asset_nid_query_key);
    }

    if (!$nid) {
      return;
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== 'item') {
      return;
    }

    // 2. Map Issue Type to Status.
    $data = $webform_submission->getData();
    $issue_type = $data[$issue_type_key] ?? 'unknown';
    
    $new_status_label = 'Degraded';
    $out_of_service_values = $this->parseConfiguredList((string) $this->configuration['out_of_service_values']);
    $degraded_values = $this->parseConfiguredList((string) $this->configuration['degraded_values']);

    if (in_array($issue_type, $out_of_service_values, TRUE)) {
      $new_status_label = 'Out of Service';
    }
    elseif (in_array($issue_type, $degraded_values, TRUE)) {
      $new_status_label = 'Degraded';
    }

    // 3. Prepare the Log Message.
    $description = $data[$description_key] ?? '';
    $user_name = $webform_submission->getOwner()->getDisplayName();
    $log_message = "Member Report ($user_name): $description";

    // 4. Update status only when escalating severity. Otherwise record a report
    // without changing the current status.
    $term = $this->assetAvailability->getTermByLabel($new_status_label);
    $current_term = $node->get('field_item_status')->entity;
    $current_label = $current_term ? $current_term->label() : NULL;

    if ($term && $this->shouldEscalateStatus($current_label, $new_status_label)) {
      if ((int) $node->get('field_item_status')->target_id !== (int) $term->id()) {
        // Attach the log message so hook_entity_update sees it.
        $node->_asset_status_log_message = $log_message;
        $node->set('field_item_status', $term->id());
        $node->save();
      }
      $this->messenger()->addStatus($this->t('Asset status updated to @status.', ['@status' => $new_status_label]));
      return;
    }

    $owner = $webform_submission->getOwner();
    $uid = $owner ? (int) $owner->id() : 0;
    if ($uid <= 0) {
      $uid = (int) $node->getOwnerId();
    }
    if ($uid <= 0) {
      $uid = 1;
    }

    $this->entityTypeManager->getStorage('asset_log_entry')->create([
      'type' => 'inspection',
      'asset' => $node->id(),
      'summary' => (string) $this->t('Member issue report (@issue)', ['@issue' => $issue_type]),
      'details' => $log_message,
      'reported_status' => $term ? $term->id() : NULL,
      'confirmed_status' => $node->get('field_item_status')->target_id ?: NULL,
      'user_id' => $uid,
    ])->save();
  }

  /**
   * Returns TRUE when the requested status is strictly worse than current.
   */
  private function shouldEscalateStatus(?string $current, string $requested): bool {
    $rank = [
      'Operational' => 0,
      'Degraded' => 1,
      'Setup / Training Only' => 1,
      'Out of Service' => 2,
      'Storage' => 2,
    ];

    $requested_rank = $rank[$requested] ?? NULL;
    if ($requested_rank === NULL) {
      return FALSE;
    }

    $current_rank = $current !== NULL && isset($rank[$current]) ? $rank[$current] : -1;
    return $requested_rank > $current_rank;
  }

  /**
   * Parses newline-delimited handler settings into string lists.
   */
  private function parseConfiguredList(string $value): array {
    $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $lines = array_map('trim', $lines);
    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
  }

}
