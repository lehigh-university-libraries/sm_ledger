<?php

namespace Drupal\sm_ledger\Message;

/**
 * Marker interface for messages tied to a ledger record.
 */
interface TrackableLedgerMessageInterface {

  /**
   * Gets the ledger record ID.
   */
  public function getEventRecordId(): int;

  /**
   * Gets the stable ledger correlation key.
   */
  public function getCorrelationKey(): string;

}
