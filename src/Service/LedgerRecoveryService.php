<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sm_ledger\Entity\EventRecord;

/**
 * Claims ledger rows for module-owned recovery and replay workflows.
 *
 * This is intentionally separate from the projection service so the ledger's
 * primary responsibility remains durable workflow state. Recovery tooling may
 * still claim rows directly for replay or one-shot drain flows when a module
 * explicitly opts into that behavior.
 */
class LedgerRecoveryService {

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
   * Constructs a ledger recovery service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private Connection $connection,
    private TimeInterface $time,
  ) {
    $this->storage = $entityTypeManager->getStorage('event_record');
    $this->baseTable = (string) $entityTypeManager
      ->getDefinition('event_record')
      ->getBaseTable();
  }

  /**
   * Claims queued work atomically for native replay/recovery processing.
   *
   * @return list<int>
   *   Claimed event record IDs in FIFO order.
   */
  public function claimRecordsForProcessing(
    string $eventKind,
    ?string $queueName,
    int $limit,
    int $staleAfter = 3600,
  ): array {
    if ($limit <= 0) {
      return [];
    }

    $now = $this->time->getRequestTime();
    $staleBefore = max(0, $now - max($staleAfter, 0));

    $candidateQuery = $this->connection->select($this->baseTable, 'er')
      ->fields('er', ['id'])
      ->condition('event_kind', $eventKind)
      ->condition('needs_processing', 1)
      ->range(0, $limit)
      ->orderBy('id', 'ASC');

    if ($queueName !== NULL && $queueName !== '') {
      $candidateQuery->condition('queue_name', $queueName);
    }

    $claimable = $candidateQuery->orConditionGroup()
      ->condition('status', EventRecord::STATUS_QUEUED)
      ->condition(
        $candidateQuery->andConditionGroup()
          ->condition('status', EventRecord::STATUS_RETRY_DUE)
          ->condition(
            $candidateQuery->orConditionGroup()
              ->condition('next_attempt_at', 0)
              ->condition('next_attempt_at', $now, '<=')
          )
      )
      ->condition(
        $candidateQuery->andConditionGroup()
          ->condition('status', EventRecord::STATUS_IN_PROGRESS)
          ->condition('last_started_at', $staleBefore, '<=')
      );
    $candidateQuery->condition($claimable);

    $candidateIds = array_map('intval', $candidateQuery->execute()->fetchCol());
    if ($candidateIds === []) {
      return [];
    }

    $claimedIds = [];
    foreach ($candidateIds as $candidateId) {
      if ($this->claimEventRecord($candidateId, $staleAfter, $now)) {
        $claimedIds[] = $candidateId;
      }
    }

    return $claimedIds;
  }

  /**
   * Atomically claims one event record for recovery execution.
   */
  private function claimEventRecord(
    int $eventRecordId,
    int $staleAfter,
    int $now,
  ): bool {
    if ($eventRecordId <= 0) {
      return FALSE;
    }

    $staleBefore = max(0, $now - max($staleAfter, 0));
    $update = $this->connection->update($this->baseTable)
      ->fields([
        'status' => EventRecord::STATUS_IN_PROGRESS,
        'last_started_at' => $now,
        'next_attempt_at' => 0,
      ])
      ->expression('attempt_count', 'attempt_count + 1')
      ->expression(
        'first_started_at',
        'CASE WHEN first_started_at = 0 '
        . 'THEN :first_started_at ELSE first_started_at END',
        [':first_started_at' => $now],
      )
      ->condition('id', $eventRecordId)
      ->condition('needs_processing', 1);

    $claimable = $update->orConditionGroup()
      ->condition('status', EventRecord::STATUS_QUEUED)
      ->condition(
        $update->andConditionGroup()
          ->condition('status', EventRecord::STATUS_RETRY_DUE)
          ->condition(
            $update->orConditionGroup()
              ->condition('next_attempt_at', 0)
              ->condition('next_attempt_at', $now, '<=')
          )
      )
      ->condition(
        $update->andConditionGroup()
          ->condition('status', EventRecord::STATUS_IN_PROGRESS)
          ->condition('last_started_at', $staleBefore, '<=')
      );
    $update->condition($claimable);

    $claimed = (int) $update->execute() === 1;
    if ($claimed) {
      $this->storage->resetCache([$eventRecordId]);
    }

    return $claimed;
  }

}
