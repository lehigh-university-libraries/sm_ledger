<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sm_ledger\Entity\EventRecord;

/**
 * Prunes old ledger projection rows according to retention policy.
 */
class LedgerRetentionService {

  /**
   * Event record storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $storage;

  /**
   * Constructs a retention service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private ConfigFactoryInterface $configFactory,
    private TimeInterface $time,
  ) {
    $this->storage = $entityTypeManager->getStorage('event_record');
  }

  /**
   * Returns configured retention windows in days keyed by status.
   *
   * @return array<string, int>
   *   Retention windows keyed by status value.
   */
  public function getConfiguredPolicies(): array {
    $retention = $this->configFactory->get('sm_ledger.settings')->get('retention');
    $retention = is_array($retention) ? $retention : [];

    return [
      EventRecord::STATUS_COMPLETED => max(0, (int) ($retention['completed_days'] ?? 30)),
      EventRecord::STATUS_ABANDONED => max(0, (int) ($retention['abandoned_days'] ?? 30)),
      EventRecord::STATUS_FAILED => max(0, (int) ($retention['failed_days'] ?? 0)),
    ];
  }

  /**
   * Counts prune candidates for the configured policy.
   *
   * @return array<string, int>
   *   Candidate counts keyed by status.
   */
  public function summarizeConfiguredCandidates(): array {
    $summary = [];
    foreach ($this->getConfiguredPolicies() as $status => $days) {
      if ($days <= 0) {
        continue;
      }
      $summary[$status] = $this->countCandidates($status, $days);
    }

    return $summary;
  }

  /**
   * Deletes prune candidates for the configured policy.
   *
   * @param int $limit
   *   Maximum records to delete per status in a single run.
   *
   * @return array<string, int>
   *   Deleted counts keyed by status.
   */
  public function pruneConfigured(int $limit = 500): array {
    $deleted = [];
    foreach ($this->getConfiguredPolicies() as $status => $days) {
      if ($days <= 0) {
        continue;
      }
      $deleted[$status] = $this->pruneStatus($status, $days, $limit);
    }

    return $deleted;
  }

  /**
   * Loads candidates for one status and retention window.
   *
   * @return \Drupal\sm_ledger\Entity\EventRecord[]
   *   Matching event records.
   */
  public function loadCandidates(string $status, int $days, int $limit = 500): array {
    $query = $this->storage->getQuery()
      // Retention runs as maintenance code over ledger rows and must ignore
      // per-entity access checks.
      ->accessCheck(FALSE)
      ->condition('status', $status)
      ->sort('id', 'ASC')
      ->range(0, max(1, $limit));
    $this->applyRetentionWindow($query, $status, $days);

    if ($status === EventRecord::STATUS_FAILED) {
      $query->condition('requires_manual_intervention', 0);
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $records = $this->storage->loadMultiple($ids);
    return is_array($records) ? $records : [];
  }

  /**
   * Counts candidates for one status and retention window.
   */
  private function countCandidates(string $status, int $days): int {
    $query = $this->storage->getQuery()
      // Retention summary is maintenance code over ledger rows and must ignore
      // per-entity access checks.
      ->accessCheck(FALSE)
      ->condition('status', $status);
    $this->applyRetentionWindow($query, $status, $days);

    if ($status === EventRecord::STATUS_FAILED) {
      $query->condition('requires_manual_intervention', 0);
    }

    return (int) $query->count()->execute();
  }

  /**
   * Deletes candidates for one status and retention window.
   */
  private function pruneStatus(string $status, int $days, int $limit): int {
    $records = $this->loadCandidates($status, $days, $limit);
    if ($records === []) {
      return 0;
    }

    $this->storage->delete($records);
    return count($records);
  }

  /**
   * Applies the age filter for one status.
   */
  private function applyRetentionWindow(object $query, string $status, int $days): void {
    $cutoff = $this->time->getRequestTime() - ($days * 86400);

    if (in_array($status, [EventRecord::STATUS_COMPLETED, EventRecord::STATUS_ABANDONED], TRUE)) {
      $query->condition('completed_at', 0, '>');
      $query->condition('completed_at', $cutoff, '<=');
      return;
    }

    $query->condition('changed', $cutoff, '<=');
  }

}
