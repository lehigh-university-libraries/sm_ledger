<?php

namespace Drupal\sm_ledger\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\sm_ledger\Message\TrackableLedgerMessageInterface;
use Drupal\sm_ledger\Service\LedgerExecutionLockServiceInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Tracks Messenger worker retry/failure lifecycle in ledger records.
 */
class WorkerLifecycleSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new WorkerLifecycleSubscriber.
   */
  public function __construct(
    private LedgerProjectionService $projection,
    private TimeInterface $time,
    private LedgerExecutionLockServiceInterface $executionLocks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      WorkerMessageHandledEvent::class => 'onMessageHandled',
      WorkerMessageRetriedEvent::class => 'onMessageRetried',
      WorkerMessageFailedEvent::class => 'onMessageFailed',
    ];
  }

  /**
   * Marks the projection completed after a successful async worker run.
   */
  public function onMessageHandled(WorkerMessageHandledEvent $event): void {
    if (!$this->executionLocks->ownsEnvelope($event->getEnvelope())) {
      return;
    }

    $eventRecordId = $this->resolveEventRecordId($event->getEnvelope());
    try {
      if ($eventRecordId <= 0 || !$this->projection->isQueuedForProcessing($eventRecordId)) {
        return;
      }

      $this->projection->markCompleted($eventRecordId);
    }
    finally {
      $this->executionLocks->releaseEnvelope($event->getEnvelope());
    }
  }

  /**
   * Persists retry schedule metadata when Messenger retries a message.
   */
  public function onMessageRetried(WorkerMessageRetriedEvent $event): void {
    if (!$this->executionLocks->ownsEnvelope($event->getEnvelope())) {
      return;
    }

    $eventRecordId = $this->resolveEventRecordId($event->getEnvelope());
    $envelope = $event->getEnvelope();
    try {
      if ($eventRecordId <= 0) {
        return;
      }

      $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
      $delayStamp = $envelope->last(DelayStamp::class);

      $retryCount = $redeliveryStamp instanceof RedeliveryStamp
        ? $redeliveryStamp->getRetryCount()
        : 0;

      $delayMs = $delayStamp instanceof DelayStamp ? $delayStamp->getDelay() : 0;
      $nextAttemptAt = $delayMs > 0
        ? $this->time->getRequestTime() + (int) ceil($delayMs / 1000)
        : 0;
      $errorDetailsStamp = $envelope->last(ErrorDetailsStamp::class);
      $errorMessage = $errorDetailsStamp instanceof ErrorDetailsStamp
        ? $errorDetailsStamp->getExceptionMessage()
        : 'Message scheduled for retry.';

      $this->projection->markRetryDue(
        $eventRecordId,
        $retryCount,
        $nextAttemptAt,
        $errorMessage
      );
    }
    finally {
      $this->executionLocks->releaseEnvelope($envelope);
    }
  }

  /**
   * Marks event records as manual intervention required on terminal failure.
   */
  public function onMessageFailed(WorkerMessageFailedEvent $event): void {
    if ($event->willRetry()) {
      return;
    }
    if (!$this->executionLocks->ownsEnvelope($event->getEnvelope())) {
      return;
    }

    $eventRecordId = $this->resolveEventRecordId($event->getEnvelope());
    try {
      if ($eventRecordId <= 0 || !$this->projection->isQueuedForProcessing($eventRecordId)) {
        return;
      }

      $this->projection->markNeedsManualIntervention(
        $eventRecordId,
        $event->getThrowable()->getMessage()
      );
    }
    finally {
      $this->executionLocks->releaseEnvelope($event->getEnvelope());
    }
  }

  /**
   * Resolves the active ledger record ID for a worker envelope.
   */
  private function resolveEventRecordId(Envelope $envelope): int {
    $message = $envelope->getMessage();
    if (!$message instanceof TrackableLedgerMessageInterface) {
      return 0;
    }

    $eventRecordId = $message->getEventRecordId();
    if ($eventRecordId > 0) {
      return $eventRecordId;
    }

    $correlationKey = $message->getCorrelationKey();
    if ($correlationKey === '') {
      return 0;
    }

    return $this->projection->findOpenByCorrelationKey($correlationKey);
  }

}
