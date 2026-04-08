<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Core\Database\Connection;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Operator\EventRecordRequeueHandlerInterface;
use Drupal\sm_ledger\Operator\EventRecordRunNowHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Coordinates generic operator actions over persisted ledger records.
 */
final class LedgerOperatorService {

  /**
   * Constructs the operator service.
   *
   * @param \Drupal\sm_ledger\Service\LedgerProjectionService $projection
   *   Ledger projection service used to update record state.
   * @param \Drupal\Core\Database\Connection $connection
   *   Database connection used for operator transactions.
   * @param \Symfony\Component\Messenger\MessageBusInterface $messageBus
   *   Message bus used to redispatch work.
   * @param iterable<object> $requeueHandlers
   *   Tagged requeue handlers.
   * @param iterable<object> $runNowHandlers
   *   Tagged run-now handlers.
   */
  public function __construct(
    private LedgerProjectionService $projection,
    private Connection $connection,
    private MessageBusInterface $messageBus,
    private iterable $requeueHandlers,
    private iterable $runNowHandlers,
  ) {}

  /**
   * Returns whether the record can be requeued.
   */
  public function canRequeue(EventRecordInterface $record): bool {
    return $this->resolveRequeueHandler($record) !== NULL;
  }

  /**
   * Returns whether the record can be executed immediately.
   */
  public function canRunNow(EventRecordInterface $record): bool {
    return $this->resolveRunNowHandler($record) !== NULL;
  }

  /**
   * Requeues one persisted ledger record.
   */
  public function requeue(EventRecordInterface $record): void {
    $handler = $this->resolveRequeueHandler($record);
    if ($handler === NULL) {
      throw new \RuntimeException(sprintf(
        'No ledger requeue handler is registered for event record %d.',
        (int) $record->id(),
      ));
    }

    $transaction = $this->connection->startTransaction();
    try {
      $this->projection->markQueuedForManualRetry((int) $record->id());
      $this->messageBus->dispatch($handler->buildMessage($record));
      unset($transaction);
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $exception;
    }
  }

  /**
   * Executes one persisted ledger record immediately.
   */
  public function runNow(EventRecordInterface $record): void {
    $handler = $this->resolveRunNowHandler($record);
    if ($handler === NULL) {
      throw new \RuntimeException(sprintf(
        'No ledger run-now handler is registered for event record %d.',
        (int) $record->id(),
      ));
    }

    $handler->runNow($record);
  }

  /**
   * Resolves the requeue handler for the record.
   */
  private function resolveRequeueHandler(EventRecordInterface $record): ?EventRecordRequeueHandlerInterface {
    foreach ($this->requeueHandlers as $handler) {
      if ($handler instanceof EventRecordRequeueHandlerInterface && $handler->supports($record)) {
        return $handler;
      }
    }

    return NULL;
  }

  /**
   * Resolves the run-now handler for the record.
   */
  private function resolveRunNowHandler(EventRecordInterface $record): ?EventRecordRunNowHandlerInterface {
    foreach ($this->runNowHandlers as $handler) {
      if ($handler instanceof EventRecordRunNowHandlerInterface && $handler->supports($record)) {
        return $handler;
      }
    }

    return NULL;
  }

}
