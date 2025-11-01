<?php

declare(strict_types=1);

namespace Drupal\asset_status\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Confirmation form for deleting asset log entry types.
 */
final class AssetLogEntryTypeDeleteForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return $this->t('Are you sure you want to delete the %label log entry type?', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->entity->delete();
    $this->messenger()->addStatus($this->t('Deleted the %label log entry type.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
