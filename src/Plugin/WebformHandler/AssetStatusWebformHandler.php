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
    // Try URL parameter first (legacy support).
    $nid = \Drupal::request()->query->get('asset_nid');
    
    // Fallback: Check if the webform has a field named 'asset_nid' or 'asset'.
    if (!$nid) {
      $data = $webform_submission->getData();
      $nid = $data['asset_nid'] ?? ($data['asset'] ?? NULL);
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
    $issue_type = $data['issue_type'] ?? 'unknown';
    
    $new_status_label = 'Operational'; // Default safety fallback.

    switch ($issue_type) {
      case 'broken_nonfuctional':
      case 'tool_missing':
        $new_status_label = 'Out of Service';
        break;

      case 'damaged_functional':
      case 'parts_missing':
      case 'supplies_missing':
        $new_status_label = 'Degraded';
        break;
      
      default:
        // If unknown issue, assume Degraded to be safe but not fully offline?
        // Or keep current status? Let's assume Degraded if they are reporting an issue.
        $new_status_label = 'Degraded';
        break;
    }

    // 3. Prepare the Log Message.
    $description = $data['describe_what_is_wrong'] ?? '';
    $user_name = $webform_submission->getOwner()->getDisplayName();
    $log_message = "Member Report ($user_name): $description";

    // 4. Update the Node (Triggers Hooks).
    // We only update if the status is actually "worse" or different.
    // But for simplicity, we force the update so the log is generated.
    $term = $this->assetAvailability->getTermByLabel($new_status_label);
    
    if ($term) {
      // Attach the log message so hook_entity_update sees it.
      $node->_asset_status_log_message = $log_message;
      
      // Update status.
      $node->set('field_item_status', $term->id());
      
      // Save node. This triggers 'asset_status_handle_node_status_change',
      // which creates the log entry and triggers Slack.
      $node->save();
      
      $this->messenger()->addStatus($this->t('Asset status updated to @status.', ['@status' => $new_status_label]));
    }
  }

}
