<?php

declare(strict_types=1);

namespace Drupal\sm_ledger\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures SM Ledger retention, recovery, and dispatch settings.
 */
final class SmLedgerSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['sm_ledger.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'sm_ledger_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('sm_ledger.settings');
    $heartbeatInterval = (int) $config->get('recovery.heartbeat_interval_seconds');
    $staleClaimThreshold = (int) $config->get('recovery.stale_claim_threshold_seconds');

    $form['help'] = [
      '#type' => 'details',
      '#title' => $this->t('How To Tune These Settings'),
      '#open' => TRUE,
      '#weight' => -20,
    ];
    $form['help']['summary'] = [
      '#type' => 'item',
      '#markup' => $this->t('Use this page to tune how long ledger records stay in the hot table, how native replay decides a claim is stale, and how aggressively SQL-backed dispatch retries transient database conflicts.'),
    ];
    $form['help']['recovery_summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Current recovery envelope'),
      '#markup' => $this->t('Heartbeat interval is @heartbeat seconds and stale-claim threshold is @threshold seconds, leaving @gap seconds between a healthy heartbeat and stale recovery.', [
        '@heartbeat' => $heartbeatInterval,
        '@threshold' => $staleClaimThreshold,
        '@gap' => max(0, $staleClaimThreshold - $heartbeatInterval),
      ]),
    ];

    $form['retention'] = [
      '#type' => 'details',
      '#title' => $this->t('Retention'),
      '#open' => TRUE,
    ];
    $form['retention']['completed_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Completed retention (days)'),
      '#default_value' => (int) $config->get('retention.completed_days'),
      '#min' => 0,
      '#description' => $this->t('How long successfully finished records remain in the hot operator table before prune/archive tooling can remove them.'),
    ];
    $form['retention']['abandoned_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Abandoned retention (days)'),
      '#default_value' => (int) $config->get('retention.abandoned_days'),
      '#min' => 0,
      '#description' => $this->t('How long abandoned records remain visible for operator review before prune/archive tooling can remove them.'),
    ];
    $form['retention']['failed_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Failed retention (days)'),
      '#default_value' => (int) $config->get('retention.failed_days'),
      '#min' => 0,
      '#description' => $this->t('How long failed records remain available for troubleshooting. Use 0 only when failures are captured elsewhere and should be pruned immediately by policy.'),
    ];

    $form['dedupe'] = [
      '#type' => 'details',
      '#title' => $this->t('Deduplication'),
      '#open' => TRUE,
    ];
    $form['dedupe']['ttl_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Deduplication TTL (seconds)'),
      '#default_value' => (int) $config->get('dedupe.ttl_seconds'),
      '#min' => 1,
      '#description' => $this->t('How long recent dedupe keys suppress duplicate queueing attempts. Increase this when duplicate producer events arrive over a wider time window; decrease it when legitimate requeueing needs to happen sooner.'),
    ];

    $form['recovery'] = [
      '#type' => 'details',
      '#title' => $this->t('Recovery'),
      '#open' => TRUE,
    ];
    $form['recovery']['stale_claim_threshold_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Stale-claim threshold (seconds)'),
      '#default_value' => (int) $config->get('recovery.stale_claim_threshold_seconds'),
      '#min' => 1,
      '#description' => $this->t('Claims older than this are considered stale and eligible for requeue. Keep this above the heartbeat interval and above any normal pauses in worker progress.'),
    ];
    $form['recovery']['heartbeat_interval_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Heartbeat interval (seconds)'),
      '#default_value' => $heartbeatInterval,
      '#min' => 1,
      '#description' => $this->t('How often long-running native replay work refreshes its claim timestamp. Shorter intervals reduce false stale recovery at the cost of more ledger writes.'),
    ];

    $form['dispatch'] = [
      '#type' => 'details',
      '#title' => $this->t('Dispatch retries'),
      '#open' => TRUE,
    ];
    $form['dispatch']['deadlock_retry_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Deadlock retry attempts'),
      '#default_value' => (int) $config->get('dispatch.deadlock_retry_attempts'),
      '#min' => 1,
      '#description' => $this->t('Maximum attempts for transient PostgreSQL/MySQL transaction conflicts such as deadlocks and serialization failures. Keep this small and bounded so real failures still surface quickly.'),
    ];
    $form['dispatch']['deadlock_retry_delay_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Deadlock retry base delay (milliseconds)'),
      '#default_value' => (int) $config->get('dispatch.deadlock_retry_delay_ms'),
      '#min' => 1,
      '#description' => $this->t('Base delay between retry attempts. Later attempts back off linearly from this value, so higher numbers reduce conflict pressure but increase enqueue latency during contention.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach ([
      'completed_days',
      'abandoned_days',
      'failed_days',
    ] as $field) {
      if ((int) $form_state->getValue($field) < 0) {
        $form_state->setErrorByName($field, $this->t('Retention values cannot be negative.'));
      }
    }

    $minimums = [
      'ttl_seconds' => $this->t('Deduplication TTL must be at least 1 second.'),
      'stale_claim_threshold_seconds' => $this->t('Stale-claim threshold must be at least 1 second.'),
      'heartbeat_interval_seconds' => $this->t('Heartbeat interval must be at least 1 second.'),
      'deadlock_retry_attempts' => $this->t('Deadlock retry attempts must be at least 1.'),
      'deadlock_retry_delay_ms' => $this->t('Deadlock retry delay must be at least 1 millisecond.'),
    ];
    foreach ($minimums as $field => $message) {
      if ((int) $form_state->getValue($field) < 1) {
        $form_state->setErrorByName($field, $message);
      }
    }

    if ((int) $form_state->getValue('heartbeat_interval_seconds') >= (int) $form_state->getValue('stale_claim_threshold_seconds')) {
      $form_state->setErrorByName('heartbeat_interval_seconds', $this->t('Heartbeat interval must be lower than the stale-claim threshold.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('sm_ledger.settings')
      ->set('retention.completed_days', (int) $form_state->getValue('completed_days'))
      ->set('retention.abandoned_days', (int) $form_state->getValue('abandoned_days'))
      ->set('retention.failed_days', (int) $form_state->getValue('failed_days'))
      ->set('dedupe.ttl_seconds', (int) $form_state->getValue('ttl_seconds'))
      ->set('recovery.stale_claim_threshold_seconds', (int) $form_state->getValue('stale_claim_threshold_seconds'))
      ->set('recovery.heartbeat_interval_seconds', (int) $form_state->getValue('heartbeat_interval_seconds'))
      ->set('dispatch.deadlock_retry_attempts', (int) $form_state->getValue('deadlock_retry_attempts'))
      ->set('dispatch.deadlock_retry_delay_ms', (int) $form_state->getValue('deadlock_retry_delay_ms'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
