<?php

namespace Drupal\sm_ledger\Service;

use Drupal\sm_ledger\Message\TrackableLedgerMessageInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * Interface for coordinating per-message execution locks.
 */
interface LedgerExecutionLockServiceInterface {

  /**
   * Attempts to acquire the execution lock for a trackable message.
   */
  public function acquire(TrackableLedgerMessageInterface $message): bool;

  /**
   * Returns whether this process owns the execution lock for the envelope.
   */
  public function ownsEnvelope(Envelope $envelope): bool;

  /**
   * Returns whether this process owns the execution lock for the message.
   */
  public function ownsMessage(TrackableLedgerMessageInterface $message): bool;

  /**
   * Releases the execution lock for the envelope if this process owns it.
   */
  public function releaseEnvelope(Envelope $envelope): void;

  /**
   * Releases the execution lock for the message if this process owns it.
   */
  public function release(TrackableLedgerMessageInterface $message): void;

}
