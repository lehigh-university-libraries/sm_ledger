<?php

namespace Drupal\sm_ledger\Operator;

use Drupal\sm_ledger\Entity\EventRecordInterface;

/**
 * Rebuilds a dispatchable message for one persisted ledger record.
 */
interface EventRecordRequeueHandlerInterface {

  /**
   * Returns whether this handler can rebuild a message for the record.
   */
  public function supports(EventRecordInterface $record): bool;

  /**
   * Builds the message to requeue for the record.
   */
  public function buildMessage(EventRecordInterface $record): object;

}
