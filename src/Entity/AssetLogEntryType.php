<?php

declare(strict_types=1);

namespace Drupal\asset_status\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * Asset log entry bundle definition.
 *
 * @ConfigEntityType(
 *   id = "asset_log_entry_type",
 *   label = @Translation("Asset log entry type"),
 *   label_collection = @Translation("Asset log entry types"),
 *   handlers = {
 *     "form" = {
 *       "add" = "Drupal\asset_status\Form\AssetLogEntryTypeForm",
 *       "edit" = "Drupal\asset_status\Form\AssetLogEntryTypeForm",
 *       "delete" = "Drupal\asset_status\Form\AssetLogEntryTypeDeleteForm"
 *     },
 *     "list_builder" = "Drupal\asset_status\AssetLogEntryTypeListBuilder"
 *   },
 *   config_prefix = "asset_log_entry_type",
 *   admin_permission = "administer asset log entries",
 *   bundle_of = "asset_log_entry",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "default_workflow_state"
 *   },
 *   links = {
 *     "add-form" = "/admin/structure/asset-log-entry-types/add",
 *     "edit-form" = "/admin/structure/asset-log-entry-types/manage/{asset_log_entry_type}",
 *     "delete-form" = "/admin/structure/asset-log-entry-types/manage/{asset_log_entry_type}/delete",
 *     "collection" = "/admin/structure/asset-log-entry-types"
 *   }
 * )
 */
final class AssetLogEntryType extends ConfigEntityBundleBase implements AssetLogEntryTypeInterface {

  /**
   * The machine-name ID.
   */
  protected string $id;

  /**
   * Human-friendly label.
   */
  protected string $label;

  /**
   * Optional bundle description.
   */
  protected ?string $description = NULL;

  /**
   * The default workflow state machine key.
   */
  protected ?string $default_workflow_state = NULL;

  /**
   * {@inheritdoc}
   */
  public function getDefaultWorkflowState(): ?string {
    return $this->default_workflow_state;
  }

  /**
   * {@inheritdoc}
   */
  public function setDefaultWorkflowState(?string $state): self {
    $this->default_workflow_state = $state;
    return $this;
  }

}
