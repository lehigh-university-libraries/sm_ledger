<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\sm_ledger\Message\TrackableLedgerMessageInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * Coordinates per-message execution locks across worker processes.
 */
final class LedgerExecutionLockService implements LedgerExecutionLockServiceInterface {

  /**
   * Lock names owned by this process, keyed by message identity.
   *
   * @var array<string, string>
   */
  private array $ownedLocks = [];

  /**
   * Constructs the execution lock service.
   */
  public function __construct(
    private LockBackendInterface $lock,
    private ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Attempts to acquire the execution lock for a trackable message.
   */
  public function acquire(TrackableLedgerMessageInterface $message): bool {
    $identity = $this->messageIdentity($message);
    if ($identity === '') {
      return FALSE;
    }

    if (isset($this->ownedLocks[$identity])) {
      return TRUE;
    }

    $lockName = $this->buildLockName($identity);
    if (!$this->lock->acquire($lockName, $this->lockTtlSeconds())) {
      return FALSE;
    }

    $this->ownedLocks[$identity] = $lockName;
    return TRUE;
  }

  /**
   * Returns whether this process owns the execution lock for the envelope.
   */
  public function ownsEnvelope(Envelope $envelope): bool {
    $message = $envelope->getMessage();
    return $message instanceof TrackableLedgerMessageInterface
      && $this->ownsMessage($message);
  }

  /**
   * Returns whether this process owns the execution lock for the message.
   */
  public function ownsMessage(TrackableLedgerMessageInterface $message): bool {
    $identity = $this->messageIdentity($message);
    return $identity !== '' && isset($this->ownedLocks[$identity]);
  }

  /**
   * Releases the execution lock for the envelope if this process owns it.
   */
  public function releaseEnvelope(Envelope $envelope): void {
    $message = $envelope->getMessage();
    if ($message instanceof TrackableLedgerMessageInterface) {
      $this->release($message);
    }
  }

  /**
   * Releases the execution lock for the message if this process owns it.
   */
  public function release(TrackableLedgerMessageInterface $message): void {
    $identity = $this->messageIdentity($message);
    if ($identity === '' || !isset($this->ownedLocks[$identity])) {
      return;
    }

    $this->lock->release($this->ownedLocks[$identity]);
    unset($this->ownedLocks[$identity]);
  }

  /**
   * Builds a stable identity for one trackable message.
   */
  private function messageIdentity(TrackableLedgerMessageInterface $message): string {
    $correlationKey = trim($message->getCorrelationKey());
    if ($correlationKey !== '') {
      return 'correlation:' . $correlationKey;
    }

    $eventRecordId = $message->getEventRecordId();
    return $eventRecordId > 0 ? 'record:' . $eventRecordId : '';
  }

  /**
   * Returns the lock name for one message identity.
   */
  private function buildLockName(string $identity): string {
    return 'sm_ledger:execution:' . hash('sha256', $identity);
  }

  /**
   * Returns the execution lock TTL in seconds.
   */
  private function lockTtlSeconds(): float {
    $threshold = (int) $this->configFactory
      ->get('sm_ledger.settings')
      ->get('recovery.stale_claim_threshold_seconds');
    return (float) max(300, $threshold ?: 3600);
  }

}
