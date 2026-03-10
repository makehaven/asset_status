<?php

declare(strict_types=1);

namespace Drupal\asset_status\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quick status update form for use from the maintenance dashboard.
 *
 * Staff select a new status and optionally add a note. Saving creates a
 * status_change log entry whose postSave() syncs the confirmed_status back to
 * the asset node automatically, so the node edit form is never needed for
 * routine triage.
 */
final class AssetQuickStatusForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the form.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'asset_quick_status_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node || $node->bundle() !== 'item') {
      $form['error'] = ['#markup' => $this->t('Invalid tool node.')];
      return $form;
    }

    $form_state->set('node', $node);

    $current_term  = $node->get('field_item_status')->entity;
    $current_label = $current_term ? $current_term->label() : $this->t('Not set');

    // Tool info header.
    $form['tool_info'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['quick-status-tool-info']],
      '#value' => '<strong>' . $this->t('Tool:') . '</strong> '
        . htmlspecialchars($node->getTitle())
        . ' &nbsp;|&nbsp; <strong>' . $this->t('Current Status:') . '</strong> '
        . '<span class="current-status-label">' . htmlspecialchars((string) $current_label) . '</span>',
    ];

    // If in "Reported Concern" state, surface the member's original report so
    // staff can make an informed decision without navigating elsewhere.
    if ((string) $current_label === 'Reported Concern') {
      $log_storage = $this->entityTypeManager->getStorage('asset_log_entry');
      $ids = $log_storage->getQuery()
        ->condition('asset', $node->id())
        ->condition('type', 'inspection')
        ->sort('created', 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE)
        ->execute();

      if ($ids) {
        $log = $log_storage->load(reset($ids));
        if ($log) {
          $reporter = $log->getOwner();
          $reporter_name  = $reporter ? $reporter->getDisplayName() : $this->t('Unknown');
          $reported_term  = $log->getReportedStatus();
          $reported_label = $reported_term ? $reported_term->label() : $this->t('Unknown');
          $details        = $log->getDetails() ?: $log->getSummary();
          // Strip the auto-prepended "Member Report (name): " prefix for readability.
          if (preg_match('/^Member Report \([^)]+\): (.+)$/s', (string) $details, $m)) {
            $details = $m[1];
          }
          $reported_at = \Drupal::service('date.formatter')->format($log->getCreatedTime(), 'short');

          $form['member_report'] = [
            '#type' => 'details',
            '#title' => $this->t("Member's report (@time)", ['@time' => $reported_at]),
            '#open' => TRUE,
            '#attributes' => ['class' => ['quick-status-member-report']],
            'body' => [
              '#markup' => '<dl>'
                . '<dt>' . $this->t('Reported by') . '</dt><dd>' . htmlspecialchars((string) $reporter_name) . '</dd>'
                . '<dt>' . $this->t("Member's assessment") . '</dt><dd>' . htmlspecialchars((string) $reported_label) . '</dd>'
                . '<dt>' . $this->t('Description') . '</dt><dd>' . nl2br(htmlspecialchars((string) $details)) . '</dd>'
                . '</dl>',
            ],
          ];
        }
      }
    }

    // Build status options from all item_status terms.
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'item_status']);
    $status_options = [];
    foreach ($terms as $term) {
      $status_options[(int) $term->id()] = $term->label();
    }
    asort($status_options);

    $form['new_status'] = [
      '#type' => 'select',
      '#title' => $this->t('New Status'),
      '#options' => $status_options,
      '#default_value' => $current_term ? (int) $current_term->id() : NULL,
      '#required' => TRUE,
    ];

    $form['staff_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Staff note'),
      '#description' => $this->t('Describe what you found and/or what action was taken. Appears in the maintenance history.'),
      '#rows' => 3,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update Status'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('asset_status.maintenance_dashboard'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node           = $form_state->get('node');
    $new_status_tid = (int) $form_state->getValue('new_status');
    $staff_note     = trim((string) $form_state->getValue('staff_note'));

    $new_term = $this->entityTypeManager->getStorage('taxonomy_term')->load($new_status_tid);
    if (!$new_term) {
      $this->messenger()->addError($this->t('Invalid status selected.'));
      return;
    }

    $current_user = \Drupal::currentUser();
    $summary      = (string) $this->t('Staff review: status set to @status', ['@status' => $new_term->label()]);
    $details      = $staff_note
      ? $current_user->getDisplayName() . ': ' . $staff_note
      : (string) $this->t('Status confirmed by @user via maintenance dashboard.', ['@user' => $current_user->getDisplayName()]);

    // Creating a status_change log entry with confirmed_status set triggers
    // AssetLogEntry::postSave() to sync the status back to the node.
    $this->entityTypeManager->getStorage('asset_log_entry')->create([
      'type'             => 'status_change',
      'asset'            => $node->id(),
      'summary'          => $summary,
      'details'          => $details,
      'confirmed_status' => $new_status_tid,
      'reported_status'  => $new_status_tid,
      'user_id'          => $current_user->id(),
    ])->save();

    $this->messenger()->addStatus($this->t('@tool status updated to @status.', [
      '@tool'   => $node->getTitle(),
      '@status' => $new_term->label(),
    ]));

    $form_state->setRedirectUrl(Url::fromRoute('asset_status.maintenance_dashboard'));
  }

}
