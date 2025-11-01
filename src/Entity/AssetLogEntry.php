<?php

declare(strict_types=1);

namespace Drupal\asset_status\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the asset log entry entity.
 *
 * @ContentEntityType(
 *   id = "asset_log_entry",
 *   label = @Translation("Asset log entry"),
 *   label_collection = @Translation("Asset log entries"),
 *   label_singular = @Translation("asset log entry"),
 *   label_plural = @Translation("asset log entries"),
 *   label_count = @PluralTranslation(
 *     singular = "@count asset log entry",
 *     plural = "@count asset log entries"
 *   ),
 *   bundle_label = @Translation("Log entry type"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\asset_status\Entity\AssetLogEntryListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\asset_status\Form\AssetLogEntryForm",
 *       "add" = "Drupal\asset_status\Form\AssetLogEntryForm",
 *       "edit" = "Drupal\asset_status\Form\AssetLogEntryForm",
 *       "delete" = "Drupal\asset_status\Form\AssetLogEntryDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     },
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler"
 *   },
 *   base_table = "asset_log_entry",
 *   revision_table = "asset_log_entry_revision",
 *   revision_data_table = "asset_log_entry_field_revision",
 *   show_revision_ui = TRUE,
 *   admin_permission = "administer asset log entries",
 *   translatable = FALSE,
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "revision_id",
 *     "label" = "summary",
 *     "uuid" = "uuid",
 *     "uid" = "user_id",
 *     "langcode" = "langcode",
 *     "bundle" = "type",
 *     "status" = "status"
 *   },
 *   bundle_entity_type = "asset_log_entry_type",
 *   field_ui_base_route = "entity.asset_log_entry_type.collection",
 *   links = {
 *     "canonical" = "/admin/content/asset-log/{asset_log_entry}",
 *     "add-page" = "/admin/content/asset-log/add",
 *     "add-form" = "/admin/content/asset-log/add/{asset_log_entry_type}",
 *     "edit-form" = "/admin/content/asset-log/{asset_log_entry}/edit",
 *     "delete-form" = "/admin/content/asset-log/{asset_log_entry}/delete",
 *     "collection" = "/admin/content/asset-log"
 *   }
 * )
 */
final class AssetLogEntry extends ContentEntityBase implements AssetLogEntryInterface {

  use EntityOwnerTrait;
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id']
      ->setReadOnly(TRUE);

    $fields['revision_id']
      ->setLabel(t('Revision ID'))
      ->setDescription(t('The revision ID.'))
      ->setReadOnly(TRUE);

    $fields['uuid']
      ->setReadOnly(TRUE);

    $fields['langcode']
      ->setLabel(t('Language'))
      ->setDescription(t('The log entry language.'))
      ->setRevisionable(TRUE);

    $fields['user_id']
      ->setLabel(t('Logged by'))
      ->setDescription(t('The user who created the log entry.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE)
      ->setRequired(TRUE);

    $fields['summary'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Summary'))
      ->setDescription(t('Short summary describing the log entry.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -20,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['details'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Details'))
      ->setDescription(t('Optional detailed notes describing the issue, maintenance performed, or resolution.'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 10,
        'settings' => [
          'rows' => 6,
        ],
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['asset'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Asset'))
      ->setDescription(t('Asset node this log entry pertains to.'))
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'target_bundles' => ['item' => 'item'],
      ])
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -19,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -19,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['reported_status'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Reported status'))
      ->setDescription(t('Status reported by the member or system.'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'target_bundles' => ['item_status' => 'item_status'],
      ])
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['confirmed_status'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Confirmed status'))
      ->setDescription(t('Status confirmed by staff after investigation.'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'target_bundles' => ['item_status' => 'item_status'],
      ])
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -9,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDefaultValue(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 100,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the log entry was created.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the log entry was last edited.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE);

    $fields['revision_log'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Revision log message'))
      ->setDescription(t('Additional information about the revision.'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 90,
      ]);

    $fields['revision_user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Revision user'))
      ->setDescription(t('The user who created the revision.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback('\Drupal\asset_status\Entity\AssetLogEntry::getCurrentUserId')
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE);

    $fields['revision_created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Revision created'))
      ->setDescription(t('The time that the revision was created.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values): void {
    parent::preCreate($storage, $values);
    if (!isset($values['user_id']) && \Drupal::currentUser()) {
      $values['user_id'] = \Drupal::currentUser()->id();
    }
  }

  /**
   * Provides a lazy callback used for revision authors.
   */
  public static function getCurrentUserId(): array {
    return [\Drupal::currentUser()->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $summary = $this->get('summary')->value;
    return $summary ?: $this->t('Asset log entry');
  }

  /**
   * {@inheritdoc}
   */
  public function getAsset(): ?\Drupal\node\NodeInterface {
    return $this->get('asset')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setAsset(\Drupal\node\NodeInterface $asset): self {
    $this->set('asset', $asset->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getReportedStatus(): ?\Drupal\taxonomy\TermInterface {
    return $this->get('reported_status')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmedStatus(): ?\Drupal\taxonomy\TermInterface {
    return $this->get('confirmed_status')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfirmedStatus(?\Drupal\taxonomy\TermInterface $term): self {
    $this->set('confirmed_status', $term ? $term->id() : NULL);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): string {
    return (string) $this->get('summary')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSummary(string $summary): self {
    $this->set('summary', $summary);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDetails(): ?string {
    return $this->get('details')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setDetails(?string $details): self {
    $this->set('details', $details);
    return $this;
  }

}
