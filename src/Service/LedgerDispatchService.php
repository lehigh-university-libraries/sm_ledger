<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Coordinates dedupe-safe ledger recording with message dispatch.
 */
class LedgerDispatchService {

  /**
   * Constructs the dispatch coordinator.
   */
  public function __construct(
    private LedgerProjectionService $projection,
    private Connection $connection,
    private LockBackendInterface $lock,
    private MessageBusInterface $messageBus,
    private ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Records and dispatches work once inside the dedupe window.
   *
   * @param \Drupal\Core\Entity\EntityInterface $sourceEntity
   *   Source entity that triggered the queued work.
   * @param string $triggerEventType
   *   Event type recorded for the ledger entry.
   * @param string $dedupeKey
   *   Dedupe token used for lock and recent-record checks.
   * @param object $message
   *   Message bus payload to dispatch after recording.
   * @param array<string, mixed> $recordValues
   *   Values forwarded to recordQueuedEvent().
   * @param float $lockLeaseSeconds
   *   Lock lease duration for the dedupe window.
   */
  public function recordAndDispatch(
    EntityInterface $sourceEntity,
    string $triggerEventType,
    string $dedupeKey,
    object $message,
    array $recordValues = [],
    float $lockLeaseSeconds = 30.0,
  ): bool {
    $lockName = $this->buildDedupeLockName($dedupeKey);
    if (!$this->lock->acquire($lockName, $lockLeaseSeconds)) {
      return FALSE;
    }

    try {
      $attempt = 0;
      $maxAttempts = $this->deadlockRetryAttempts();

      while (TRUE) {
        $attempt++;
        try {
          $transaction = $this->connection->startTransaction();
          if ($this->projection->findRecentByDedupeKey($dedupeKey) > 0) {
            unset($transaction);
            return FALSE;
          }

          $this->projection->recordQueuedEvent($sourceEntity, $triggerEventType, $recordValues + [
            'correlation_key' => $recordValues['correlation_key'] ?? $dedupeKey,
            'dedupe_key' => $recordValues['dedupe_key'] ?? $dedupeKey,
          ]);
          $this->messageBus->dispatch($message);
          unset($transaction);
          return TRUE;
        }
        catch (\Throwable $exception) {
          if (isset($transaction)) {
            $transaction->rollBack();
          }

          if ($attempt >= $maxAttempts || !$this->isRetryableTransactionException($exception)) {
            throw $exception;
          }

          usleep($this->deadlockRetryDelayMicros($attempt));
        }
      }
    }
    catch (\Throwable $exception) {
      throw $exception;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Returns the dedupe lock name for one enqueue key.
   */
  private function buildDedupeLockName(string $dedupeKey): string {
    return 'sm_ledger:dispatch:' . hash('sha256', $dedupeKey);
  }

  /**
   * Returns the configured dispatch retry attempts.
   */
  private function deadlockRetryAttempts(): int {
    $value = (int) $this->configFactory->get('sm_ledger.settings')
      ->get('dispatch.deadlock_retry_attempts');
    return max(1, $value ?: 3);
  }

  /**
   * Returns the configured dispatch retry delay in microseconds.
   */
  private function deadlockRetryDelayMicros(int $attempt): int {
    $baseDelayMs = (int) $this->configFactory->get('sm_ledger.settings')
      ->get('dispatch.deadlock_retry_delay_ms');
    $baseDelayMs = max(1, $baseDelayMs ?: 100);
    return $baseDelayMs * 1000 * max(1, $attempt);
  }

  /**
   * Returns whether the exception looks like a transient DB transaction fault.
   */
  private function isRetryableTransactionException(\Throwable $exception): bool {
    for ($current = $exception; $current !== NULL; $current = $current->getPrevious()) {
      $code = (string) $current->getCode();
      if (in_array($code, ['40001', '40P01', '1213', '1205'], TRUE)) {
        return TRUE;
      }

      $message = strtolower($current->getMessage());
      foreach ([
        'sqlstate[40001]',
        'sqlstate[40p01]',
        'deadlock detected',
        'deadlock found when trying to get lock',
        'could not serialize access',
        'serialization failure',
        'lock wait timeout exceeded',
      ] as $needle) {
        if (str_contains($message, $needle)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

}
