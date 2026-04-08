<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_ledger\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\sm_ledger\EventSubscriber\WorkerLifecycleSubscriber;
use Drupal\sm_ledger\Service\LedgerExecutionLockServiceInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\sm_ledger\Unit\Support\TestTrackableLedgerMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Unit tests for worker lifecycle projection updates.
 */
final class WorkerLifecycleSubscriberUnitTest extends UnitTestCase {

  /**
   * Tests successful handling closes the projection.
   */
  public function testOnMessageHandledMarksCompletedForQueuedRecord(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())
      ->method('isQueuedForProcessing')
      ->with(88)
      ->willReturn(TRUE);
    $projection->expects($this->once())
      ->method('markCompleted')
      ->with(88);
    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('ownsEnvelope')
      ->willReturn(TRUE);
    $executionLocks->expects($this->once())
      ->method('releaseEnvelope');

    $subscriber = new WorkerLifecycleSubscriber(
      $projection,
      $this->createMock(TimeInterface::class),
      $executionLocks,
    );

    $subscriber->onMessageHandled(
      new WorkerMessageHandledEvent(
        new Envelope(new TestTrackableLedgerMessage(88)),
        'islandora_index_fedora',
      ),
    );
  }

  /**
   * Tests retry metadata is projected from Messenger stamps.
   */
  public function testOnMessageRetriedPersistsRetrySchedule(): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())
      ->method('markRetryDue')
      ->with(21, 3, 1700000005, 'Message scheduled for retry.');
    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('ownsEnvelope')
      ->willReturn(TRUE);
    $executionLocks->expects($this->once())
      ->method('releaseEnvelope');

    $subscriber = new WorkerLifecycleSubscriber($projection, $time, $executionLocks);
    $envelope = (new Envelope(new TestTrackableLedgerMessage(21)))
      ->with(new RedeliveryStamp(3))
      ->with(new DelayStamp(5000));

    $subscriber->onMessageRetried(
      new WorkerMessageRetriedEvent($envelope, 'islandora_derivatives'),
    );
  }

  /**
   * Tests terminal failures require manual intervention.
   */
  public function testOnMessageFailedMarksManualIntervention(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())
      ->method('isQueuedForProcessing')
      ->with(64)
      ->willReturn(TRUE);
    $projection->expects($this->once())
      ->method('markNeedsManualIntervention')
      ->with(64, 'Permanent failure.');
    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('ownsEnvelope')
      ->willReturn(TRUE);
    $executionLocks->expects($this->once())
      ->method('releaseEnvelope');

    $subscriber = new WorkerLifecycleSubscriber(
      $projection,
      $this->createMock(TimeInterface::class),
      $executionLocks,
    );

    $subscriber->onMessageFailed(
      new WorkerMessageFailedEvent(
        new Envelope(new TestTrackableLedgerMessage(64)),
        'failed',
        new \RuntimeException('Permanent failure.'),
      ),
    );
  }

  /**
   * Tests retrying failures do not prematurely mark manual intervention.
   */
  public function testOnMessageFailedSkipsWhenWorkerWillRetry(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->never())->method('markNeedsManualIntervention');
    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->never())->method('ownsEnvelope');

    $subscriber = new WorkerLifecycleSubscriber(
      $projection,
      $this->createMock(TimeInterface::class),
      $executionLocks,
    );
    $event = new WorkerMessageFailedEvent(
      new Envelope(new TestTrackableLedgerMessage(64)),
      'failed',
      new \RuntimeException('Retryable failure.'),
    );
    $event->setForRetry();

    $subscriber->onMessageFailed($event);
  }

  /**
   * Tests handled events from non-owning workers are ignored.
   */
  public function testOnMessageHandledSkipsWhenWorkerDoesNotOwnExecutionLock(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->never())->method('markCompleted');
    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('ownsEnvelope')
      ->willReturn(FALSE);
    $executionLocks->expects($this->never())->method('releaseEnvelope');

    $subscriber = new WorkerLifecycleSubscriber(
      $projection,
      $this->createMock(TimeInterface::class),
      $executionLocks,
    );

    $subscriber->onMessageHandled(
      new WorkerMessageHandledEvent(
        new Envelope(new TestTrackableLedgerMessage(88)),
        'islandora_index_fedora',
      ),
    );
  }

}
