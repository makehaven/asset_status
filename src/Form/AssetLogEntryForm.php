<?php

declare(strict_types=1);

namespace Drupal\asset_status\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

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

    // Hide summary behind an "Advanced" collapsible section.
    // The title is auto-generated; most staff never need to touch it.
    if (isset($form['summary'])) {
      $form['summary']['widget'][0]['value']['#title'] = $this->t('Log title');
      $form['summary']['widget'][0]['value']['#description'] = $this->t('Auto-generated from the tool name and date. Edit only if you need a custom title.');
      $form['advanced'] = [
        '#type' => 'details',
        '#title' => $this->t('Advanced'),
        '#open' => FALSE,
        '#weight' => 100,
      ];
      $form['advanced']['summary'] = $form['summary'];
      unset($form['summary']);
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

    // Add capture attribute to the photo widget so mobile users can take a photo.
    if (isset($form['photo']['widget'])) {
      $form['photo']['widget']['#after_build'][] = [static::class, 'addPhotoCaptureAttribute'];
    }

    // Lock the details field to plain text — no WYSIWYG editor.
    // #format and #allowed_formats must be set on the text_format element
    // itself, not on child elements, because children are created later
    // by the TextFormat::processTextFormat() #process callback.
    if (isset($form['details']['widget'][0])) {
      $form['details']['widget'][0]['#format'] = 'plain_text';
      $form['details']['widget'][0]['#allowed_formats'] = ['plain_text'];
    }
    if (isset($form['details'])) {
      $form['details']['#weight'] = 5;
    }

    return $form;
  }

  /**
   * After-build callback: adds capture/accept attributes to the photo file input.
   *
   * The `capture` attribute triggers the native camera on mobile devices.
   * `accept="image/*"` limits the file picker to images on all devices.
   */
  public static function addPhotoCaptureAttribute(array $element, FormStateInterface $form_state): array {
    foreach (Element::children($element) as $delta) {
      if (isset($element[$delta]['upload'])) {
        $element[$delta]['upload']['#attributes']['capture'] = 'environment';
        $element[$delta]['upload']['#attributes']['accept'] = 'image/*';
      }
    }
    return $element;
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
