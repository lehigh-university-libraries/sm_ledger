<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_ledger\Unit\Support;

use Drupal\sm_ledger\Message\TrackableLedgerMessageInterface;

/**
 * Simple test message tied to one ledger record.
 */
final class TestTrackableLedgerMessage implements TrackableLedgerMessageInterface {

  /**
   * Constructs a new test message.
   */
  public function __construct(
    private int $eventRecordId,
    private string $correlationKey = '',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getEventRecordId(): int {
    return $this->eventRecordId;
  }

  /**
   * {@inheritdoc}
   */
  public function getCorrelationKey(): string {
    return $this->correlationKey;
  }

}
