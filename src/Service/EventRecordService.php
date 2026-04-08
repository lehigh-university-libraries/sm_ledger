<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Entity\EventRecordInterface;

/**
 * Creates and updates the durable ledger projection.
 */
class EventRecordService {

  /**
   * The event record storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $storage;

  /**
   * The event record base table.
   */
  private string $baseTable;

  /**
   * Constructs an EventRecordService.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private TimeInterface $time,
    private AccountProxyInterface $currentUser,
    private Connection $connection,
    private ConfigFactoryInterface $configFactory,
  ) {
    $this->storage = $entityTypeManager->getStorage('event_record');
    $this->baseTable = (string) $entityTypeManager
      ->getDefinition('event_record')
      ->getBaseTable();
  }

  /**
   * Creates the initial ledger projection row for queued work.
   *
   * This persists ledger state only. Producer code that also dispatches onto a
   * transport is responsible for coordinating that second write.
   */
  public function recordQueuedEvent(
    EntityInterface $sourceEntity,
    string $triggerEventType,
    array $values = [],
  ): EventRecordInterface {
    $correlationKey = $values['correlation_key']
      ?? $this->buildCorrelationKey($sourceEntity, $triggerEventType, $values);
    $dedupeKey = $values['dedupe_key'] ?? $correlationKey;
    $queueName = $values['queue_name'] ?? '';

    if (
      is_string($dedupeKey)
      && $dedupeKey !== ''
      && $this->isDedupeActive()
      && $this->findRecentByDedupeKey($dedupeKey) > 0
    ) {
      throw new \RuntimeException(sprintf(
        'A ledger event with dedupe key "%s" was already recorded within the configured dedupe window.',
        $dedupeKey,
      ));
    }

    $record = $this->storage->create($values + [
      'status' => EventRecord::STATUS_QUEUED,
      'needs_processing' => TRUE,
      'event_kind' => EventRecord::KIND_CUSTOM,
      'target_system' => 'custom',
      'source_entity_type' => $sourceEntity->getEntityTypeId(),
      'source_entity_id' => (int) $sourceEntity->id(),
      'source_entity_uuid' => (string) $sourceEntity->uuid(),
      'initiating_user_id' => (int) $this->currentUser->id(),
      'trigger_event_type' => $triggerEventType,
      'correlation_key' => $correlationKey,
      'dedupe_key' => $dedupeKey,
      'queue_name' => is_string($queueName) ? $queueName : '',
      'emitted_at' => $this->time->getRequestTime(),
    ]);
    $record->save();

    return $record;
  }

  /**
   * Finds the most recent non-completed record for the given event dimensions.
   */
  public function findOpenEventRecordId(
    string $entityType,
    int $entityId,
    string $triggerEventType,
  ): int {
    $ids = $this->storage->getQuery()
      // This is worker-side ledger lookup by business key, not end-user
      // content listing, so it must bypass entity access.
      ->accessCheck(FALSE)
      ->condition('source_entity_type', $entityType)
      ->condition('source_entity_id', $entityId)
      ->condition('trigger_event_type', $triggerEventType)
      ->condition('needs_processing', 1)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    return (int) reset($ids);
  }

  /**
   * Finds an open event record by correlation key.
   */
  public function findOpenByCorrelationKey(string $correlationKey): int {
    $ids = $this->storage->getQuery()
      // This is worker-side ledger lookup by correlation key, not end-user
      // content listing, so it must bypass entity access.
      ->accessCheck(FALSE)
      ->condition('correlation_key', $correlationKey)
      ->condition('needs_processing', 1)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    return (int) reset($ids);
  }

  /**
   * Finds an open event record by dedupe key.
   */
  public function findOpenByDedupeKey(string $dedupeKey): int {
    $ids = $this->storage->getQuery()
      // This is worker-side ledger lookup by dedupe key, not end-user
      // content listing, so it must bypass entity access.
      ->accessCheck(FALSE)
      ->condition('dedupe_key', $dedupeKey)
      ->condition('needs_processing', 1)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    return (int) reset($ids);
  }

  /**
   * Finds the most recent record for the dedupe key inside the dedupe window.
   */
  public function findRecentByDedupeKey(string $dedupeKey, ?int $ttlSeconds = NULL): int {
    if ($dedupeKey === '') {
      return 0;
    }

    $ttlSeconds ??= $this->dedupeTtlSeconds();
    if ($ttlSeconds <= 0) {
      return 0;
    }

    $ids = $this->storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('dedupe_key', $dedupeKey)
      ->condition('emitted_at', $this->time->getRequestTime() - $ttlSeconds, '>=')
      ->sort('emitted_at', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    return (int) reset($ids);
  }

  /**
   * Marks an event as in progress and increments attempt counters.
   */
  public function markProcessing(int $eventRecordId): void {
    $now = $this->time->getRequestTime();
    $this->updateRecord(
      $eventRecordId,
      [
        'status' => EventRecord::STATUS_IN_PROGRESS,
        'last_started_at' => $now,
      ],
      [
        'attempt_count' => ['attempt_count + 1', []],
        'first_started_at' => [
          'CASE WHEN first_started_at = 0 '
          . 'THEN :first_started_at ELSE first_started_at END',
          [':first_started_at' => $now],
        ],
      ],
      [
        EventRecord::STATUS_QUEUED,
        EventRecord::STATUS_RETRY_DUE,
        EventRecord::STATUS_FAILED,
      ],
    );
  }

  /**
   * Marks an event as successfully completed.
   *
   * Queued records can be closed directly by synchronous/admin flows that do
   * not transition through the worker claim lifecycle.
   */
  public function markCompleted(int $eventRecordId): void {
    $now = $this->time->getRequestTime();
    $this->updateRecord($eventRecordId, [
      'status' => EventRecord::STATUS_COMPLETED,
      'needs_processing' => 0,
      'completed_at' => $now,
      'next_attempt_at' => 0,
      'last_error' => '',
      'requires_manual_intervention' => 0,
    ], [], [
      EventRecord::STATUS_QUEUED,
      EventRecord::STATUS_IN_PROGRESS,
      EventRecord::STATUS_RETRY_DUE,
      EventRecord::STATUS_FAILED,
    ]);
  }

  /**
   * Marks an event as abandoned when processing is no longer applicable.
   *
   * Queued records can be closed directly by synchronous/admin flows that do
   * not transition through the worker claim lifecycle.
   */
  public function markAbandoned(int $eventRecordId, string $reason): void {
    $now = $this->time->getRequestTime();
    $this->updateRecord($eventRecordId, [
      'status' => EventRecord::STATUS_ABANDONED,
      'needs_processing' => 0,
      'completed_at' => $now,
      'next_attempt_at' => 0,
      'last_error' => $reason,
    ], [], [
      EventRecord::STATUS_QUEUED,
      EventRecord::STATUS_IN_PROGRESS,
      EventRecord::STATUS_RETRY_DUE,
    ]);
  }

  /**
   * Marks an event as failed without a scheduled retry.
   */
  public function markFailed(int $eventRecordId, string $error): void {
    $this->updateRecord(
      $eventRecordId,
      [
        'status' => EventRecord::STATUS_FAILED,
        'needs_processing' => 0,
        'next_attempt_at' => 0,
        'last_error' => $error,
      ],
      [
        'retry_count' => [
          'CASE WHEN attempt_count > 0 THEN attempt_count - 1 ELSE 0 END',
          [],
        ],
      ],
      [
        EventRecord::STATUS_QUEUED,
        EventRecord::STATUS_IN_PROGRESS,
        EventRecord::STATUS_RETRY_DUE,
      ],
    );
  }

  /**
   * Marks an event as retry-due from Messenger retry metadata.
   */
  public function markRetryDue(
    int $eventRecordId,
    int $retryCount,
    int $nextAttemptAt,
    string $error,
  ): void {
    $this->updateRecord(
      $eventRecordId,
      [
        'status' => EventRecord::STATUS_RETRY_DUE,
        'needs_processing' => 1,
        'next_attempt_at' => $nextAttemptAt,
        'last_error' => $error,
      ],
      [
        'retry_count' => [
          'CASE WHEN retry_count < :retry_count '
          . 'THEN :retry_count ELSE retry_count END',
          [':retry_count' => $retryCount],
        ],
      ],
      [EventRecord::STATUS_IN_PROGRESS],
    );
  }

  /**
   * Marks an event as requiring manual intervention.
   */
  public function markNeedsManualIntervention(
    int $eventRecordId,
    string $error,
  ): void {
    $now = $this->time->getRequestTime();
    $this->updateRecord(
      $eventRecordId,
      [
        'status' => EventRecord::STATUS_FAILED,
        'needs_processing' => 0,
        'requires_manual_intervention' => 1,
        'last_manual_intervention_at' => $now,
        'completed_at' => $now,
        'last_error' => $error,
      ],
      [
        'manual_intervention_count' => ['manual_intervention_count + 1', []],
      ],
      [
        EventRecord::STATUS_FAILED,
        EventRecord::STATUS_IN_PROGRESS,
        EventRecord::STATUS_RETRY_DUE,
      ],
    );
  }

  /**
   * Queues an event for manual re-run from the UI.
   *
   * This resets ledger state only. Native sweep workers will eventually claim
   * the row again, but Messenger-based producers must dispatch a new message
   * separately if they expect asynchronous processing to resume.
   */
  public function markQueuedForManualRetry(int $eventRecordId): void {
    $this->updateRecord($eventRecordId, [
      'status' => EventRecord::STATUS_QUEUED,
      'needs_processing' => 1,
      'requires_manual_intervention' => 0,
      'next_attempt_at' => $this->time->getRequestTime(),
      'last_error' => '',
      'completed_at' => 0,
    ], [], [
      EventRecord::STATUS_FAILED,
      EventRecord::STATUS_ABANDONED,
      EventRecord::STATUS_RETRY_DUE,
    ]);
  }

  /**
   * Checks whether an event record is still queued for worker processing.
   */
  public function isQueuedForProcessing(int $eventRecordId): bool {
    $record = $this->load($eventRecordId);
    return $record instanceof EventRecordInterface
      && (bool) $record->get('needs_processing')->value;
  }

  /**
   * Refreshes the heartbeat timestamp for one in-progress record.
   */
  public function markHeartbeat(int $eventRecordId): void {
    $this->updateRecord(
      $eventRecordId,
      [
        'last_started_at' => $this->time->getRequestTime(),
      ],
      [],
      [EventRecord::STATUS_IN_PROGRESS],
    );
  }

  /**
   * Applies a direct scalar update to one event record.
   *
   * @param int $eventRecordId
   *   The record identifier.
   * @param array<string, mixed> $fields
   *   Literal field values to write.
   * @param array<string, array{0: string, 1: array<string, mixed>}> $expressions
   *   SQL expressions keyed by field name.
   * @param string[] $currentStatuses
   *   Allowed current statuses for the transition. An empty array disables the
   *   status guard.
   */
  private function updateRecord(
    int $eventRecordId,
    array $fields,
    array $expressions = [],
    array $currentStatuses = [],
  ): void {
    if ($eventRecordId <= 0) {
      return;
    }

    $update = $this->connection->update($this->baseTable)
      ->fields($fields + ['changed' => $this->time->getRequestTime()])
      ->condition('id', $eventRecordId);

    if ($currentStatuses !== []) {
      $update->condition('status', $currentStatuses, 'IN');
    }

    foreach ($expressions as $field => [$expression, $arguments]) {
      $update->expression($field, $expression, $arguments);
    }

    $updated = (int) $update->execute();
    if ($updated > 0) {
      $this->storage->resetCache([$eventRecordId]);
    }
  }

  /**
   * Builds a correlation key for event dedupe/tracking.
   */
  private function buildCorrelationKey(
    EntityInterface $sourceEntity,
    string $triggerEventType,
    array $values,
  ): string {
    $actionId = (string) ($values['action_plugin_id'] ?? 'unknown');
    return sprintf('%s:%s:%s:%s',
      $actionId,
      $sourceEntity->getEntityTypeId(),
      (int) $sourceEntity->id(),
      $triggerEventType
    );
  }

  /**
   * Loads an event record by ID.
   */
  private function load(int $eventRecordId): ?EventRecordInterface {
    if ($eventRecordId <= 0) {
      return NULL;
    }

    $record = $this->storage->load($eventRecordId);
    return $record instanceof EventRecordInterface ? $record : NULL;
  }

  /**
   * Returns whether time-bounded dedupe is active.
   */
  private function isDedupeActive(): bool {
    return $this->dedupeTtlSeconds() > 0;
  }

  /**
   * Returns the configured dedupe TTL in seconds.
   */
  private function dedupeTtlSeconds(): int {
    $ttl = (int) $this->configFactory
      ->get('sm_ledger.settings')
      ->get('dedupe.ttl_seconds');
    return max(0, $ttl);
  }

}
