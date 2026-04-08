<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Entity\EventRecordInterface;

/**
 * Detects and requeues stale in-progress ledger rows.
 */
class LedgerStaleClaimService {

  /**
   * Event record storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $storage;

  /**
   * The event record base table.
   */
  private string $baseTable;

  /**
   * Constructs the stale claim service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private Connection $connection,
    private TimeInterface $time,
    private ConfigFactoryInterface $configFactory,
  ) {
    $this->storage = $entityTypeManager->getStorage('event_record');
    $this->baseTable = (string) $entityTypeManager
      ->getDefinition('event_record')
      ->getBaseTable();
  }

  /**
   * Counts stale in-progress rows.
   */
  public function countStaleClaims(?int $thresholdSeconds = NULL): int {
    $thresholdSeconds ??= $this->configuredThresholdSeconds();
    return (int) $this->connection->select($this->baseTable, 'er')
      ->condition('status', EventRecord::STATUS_IN_PROGRESS)
      ->condition('needs_processing', 1)
      ->condition('last_started_at', $this->getStaleBefore($thresholdSeconds), '<=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Loads stale in-progress rows for inspection.
   *
   * @return \Drupal\sm_ledger\Entity\EventRecordInterface[]
   *   Matching stale rows.
   */
  public function loadStaleClaims(
    ?int $thresholdSeconds = NULL,
    int $limit = 100,
  ): array {
    $thresholdSeconds ??= $this->configuredThresholdSeconds();
    $ids = $this->loadStaleClaimIds($thresholdSeconds, $limit);
    if ($ids === []) {
      return [];
    }

    $records = $this->storage->loadMultiple(array_map('intval', $ids));

    return array_values(array_filter(
      $records,
      static fn (mixed $record): bool => $record instanceof EventRecordInterface,
    ));
  }

  /**
   * Requeues stale in-progress rows.
   *
   * @return list<int>
   *   Requeued record IDs.
   */
  public function requeueStaleClaims(
    ?int $thresholdSeconds = NULL,
    int $limit = 100,
  ): array {
    $thresholdSeconds ??= $this->configuredThresholdSeconds();
    $ids = $this->loadStaleClaimIds($thresholdSeconds, $limit);
    if ($ids === []) {
      return [];
    }

    $now = $this->time->getRequestTime();
    $this->connection->update($this->baseTable)
      ->fields([
        'status' => EventRecord::STATUS_QUEUED,
        'next_attempt_at' => $now,
        'last_error' => 'Automatically requeued after stale in-progress timeout.',
        'changed' => $now,
      ])
      ->condition('id', $ids, 'IN')
      ->condition('status', EventRecord::STATUS_IN_PROGRESS)
      ->condition('needs_processing', 1)
      ->execute();

    $this->storage->resetCache($ids);

    return $ids;
  }

  /**
   * Calculates the timestamp cutoff for stale claims.
   */
  private function getStaleBefore(int $thresholdSeconds): int {
    return max(0, $this->time->getRequestTime() - max(0, $thresholdSeconds));
  }

  /**
   * Returns the configured stale-claim threshold in seconds.
   */
  private function configuredThresholdSeconds(): int {
    $configured = (int) $this->configFactory
      ->get('sm_ledger.settings')
      ->get('recovery.stale_claim_threshold_seconds');
    return max(1, $configured ?: 3600);
  }

  /**
   * Returns the effective stale-claim threshold.
   */
  public function getThresholdSeconds(): int {
    return $this->configuredThresholdSeconds();
  }

  /**
   * Loads stale claim IDs without hydrating entity objects.
   *
   * @return list<int>
   *   Matching stale row IDs.
   */
  private function loadStaleClaimIds(
    ?int $thresholdSeconds = NULL,
    int $limit = 100,
  ): array {
    $thresholdSeconds ??= $this->configuredThresholdSeconds();
    return array_map('intval', $this->connection->select($this->baseTable, 'er')
      ->fields('er', ['id'])
      ->condition('status', EventRecord::STATUS_IN_PROGRESS)
      ->condition('needs_processing', 1)
      ->condition('last_started_at', $this->getStaleBefore($thresholdSeconds), '<=')
      ->orderBy('last_started_at', 'ASC')
      ->range(0, max(1, $limit))
      ->execute()
      ->fetchCol());
  }

}
