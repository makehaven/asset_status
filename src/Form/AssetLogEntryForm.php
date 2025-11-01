<?php

declare(strict_types=1);

namespace Drupal\asset_status\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Handles add/edit forms for asset log entries.
 */
final class AssetLogEntryForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\asset_status\Entity\AssetLogEntryInterface $entity */
    $form = parent::buildForm($form, $form_state);
    $entity = $this->entity;

    $form['#title'] = $entity->isNew()
      ? $this->t('Create asset log entry')
      : $this->t('Edit log entry %label', ['%label' => $entity->label()]);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\asset_status\Entity\AssetLogEntryInterface $entity */
    $entity = $this->entity;
    $status = parent::save($form, $form_state);

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created log entry %label.', ['%label' => $entity->label()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Updated log entry %label.', ['%label' => $entity->label()]));
    }

    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $status;
  }

}
