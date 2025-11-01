<?php

declare(strict_types=1);

namespace Drupal\asset_status\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Bundle form for asset log entry types.
 */
final class AssetLogEntryTypeForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\asset_status\Entity\AssetLogEntryTypeInterface $entity */
    $form = parent::form($form, $form_state);
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#disabled' => !$entity->isNew(),
      '#machine_name' => [
        'exists' => '\Drupal\asset_status\Entity\AssetLogEntryType::load',
      ],
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $entity->get('description'),
      '#description' => $this->t('Explain when this log entry type should be used.'),
      '#rows' => 4,
    ];

    $form['default_workflow_state'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default workflow state'),
      '#default_value' => $entity->getDefaultWorkflowState(),
      '#description' => $this->t('Optional workflow state machine key to apply when new entries are created.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\asset_status\Entity\AssetLogEntryTypeInterface $entity */
    $entity = $this->entity;
    $entity->set('description', $form_state->getValue('description'));
    $entity->setDefaultWorkflowState($form_state->getValue('default_workflow_state') ?: NULL);

    $status = $entity->save();

    $this->messenger()->addStatus($this->t('Saved the %label log entry type.', ['%label' => $entity->label()]));
    if ($status === SAVED_NEW) {
      $form_state->setRedirectUrl($entity->toUrl('collection'));
    }
    else {
      $form_state->setRedirectUrl($entity->toUrl('collection'));
    }
  }

}
