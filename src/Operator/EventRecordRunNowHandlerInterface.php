<?php

namespace Drupal\sm_ledger\Operator;

use Drupal\sm_ledger\Entity\EventRecordInterface;

/**
 * Executes a persisted ledger record synchronously.
 */
interface EventRecordRunNowHandlerInterface {

  /**
   * Returns whether this handler can run the record immediately.
   */
  public function supports(EventRecordInterface $record): bool;

  /**
   * Executes the record immediately.
   */
  public function runNow(EventRecordInterface $record): void;

}
