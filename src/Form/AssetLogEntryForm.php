<?php

declare(strict_types=1);

namespace Drupal\asset_status\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

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

    // Auto-generate the summary title if the entity is new and has an asset.
    $asset = $entity->getAsset();
    if ($entity->isNew() && $asset && empty($entity->getSummary())) {
      $date = \Drupal::service('date.formatter')
        ->format(\Drupal::time()->getRequestTime(), 'custom', 'M j, Y');
      $entity->setSummary('Maintenance – ' . $asset->label() . ' – ' . $date);
    }

    $form['#title'] = $entity->isNew()
      ? $this->t('Record Maintenance')
      : $this->t('Edit log entry');

    // Move summary to the bottom as a secondary "title" field.
    if (isset($form['summary'])) {
      $form['summary']['#weight'] = 50;
      $form['summary']['widget'][0]['value']['#title'] = $this->t('Log title (auto-generated)');
      $form['summary']['widget'][0]['value']['#description'] = $this->t('Edit this if you want a custom title for this log entry.');
    }

    // Lock the asset field to read-only display when it is already set.
    if ($asset && isset($form['asset'])) {
      $form['asset']['#access'] = FALSE;
      $form['asset_display'] = [
        '#type' => 'item',
        '#title' => $this->t('Tool'),
        '#markup' => $asset->label(),
        '#weight' => -20,
      ];
    }

    // Hide revision information — not useful for maintenance logs.
    if (isset($form['revision_information'])) {
      $form['revision_information']['#access'] = FALSE;
    }
    if (isset($form['revision_log'])) {
      $form['revision_log']['#access'] = FALSE;
    }

    // Hide published toggle — maintenance logs are always published.
    if (isset($form['status'])) {
      $form['status']['#access'] = FALSE;
    }

    // Replace the WYSIWYG details widget with a plain textarea.
    if (isset($form['details']['widget'][0])) {
      $form['details']['widget'][0]['value']['#type'] = 'textarea';
      $form['details']['widget'][0]['value']['#rows'] = 6;
      // Lock to plain_text and hide the format selector.
      if (isset($form['details']['widget'][0]['format'])) {
        $form['details']['widget'][0]['format']['format']['#default_value'] = 'plain_text';
        $form['details']['widget'][0]['format']['#access'] = FALSE;
      }
    }
    if (isset($form['details'])) {
      $form['details']['#weight'] = 5;
    }

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
      $this->messenger()->addStatus($this->t('Maintenance log saved.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Log entry updated.'));
    }

    // Redirect back to the tool page if we came from one.
    $asset = $entity->getAsset();
    if ($asset) {
      $form_state->setRedirectUrl($asset->toUrl());
    }
    else {
      $form_state->setRedirectUrl($entity->toUrl('collection'));
    }

    return $status;
  }

}
