<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_ledger\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\LedgerDispatchService;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit tests for transactional ledger dispatch retries.
 */
final class LedgerDispatchServiceUnitTest extends UnitTestCase {

  /**
   * Tests retryable transaction failures are retried before surfacing.
   */
  public function testRecordAndDispatchRetriesDeadlock(): void {
    $source = $this->createMock(EntityInterface::class);
    $record = $this->createMock(EventRecordInterface::class);
    $projection = $this->createMock(LedgerProjectionService::class);
    $recordQueuedAttempts = 0;
    $projection->expects($this->exactly(2))
      ->method('findRecentByDedupeKey')
      ->with('dedupe-key')
      ->willReturn(0);
    $projection->expects($this->exactly(2))
      ->method('recordQueuedEvent')
      ->willReturnCallback(function (EntityInterface $entity, string $eventType, array $values) use ($source, $record, &$recordQueuedAttempts): EventRecordInterface {
        $recordQueuedAttempts++;
        static::assertSame($source, $entity);
        static::assertSame('update', $eventType);
        static::assertSame('dedupe-key', $values['correlation_key']);
        static::assertSame('dedupe-key', $values['dedupe_key']);
        if ($recordQueuedAttempts === 1) {
          throw new \RuntimeException('Deadlock found when trying to get lock');
        }

        return $record;
      });

    $transactionOne = new class() {
      /**
       * Number of rollback calls.
       *
       * @var int
       */
      public int $rollbacks = 0;

      /**
       * Records one rollback invocation.
       */
      public function rollBack(): void {
        $this->rollbacks++;
      }

    };
    $transactionTwo = new class() {
      /**
       * Number of rollback calls.
       *
       * @var int
       */
      public int $rollbacks = 0;

      /**
       * Records one rollback invocation.
       */
      public function rollBack(): void {
        $this->rollbacks++;
      }

    };

    $connection = $this->createMock(Connection::class);
    $connection->expects($this->exactly(2))
      ->method('startTransaction')
      ->willReturnOnConsecutiveCalls($transactionOne, $transactionTwo);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release')->willReturn(TRUE);

    $bus = $this->createMock(MessageBusInterface::class);
    $bus->expects($this->once())->method('dispatch')->with($this->isInstanceOf(\stdClass::class))->willReturn(new Envelope(new \stdClass()));

    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnMap([
        ['dispatch.deadlock_retry_attempts', 2],
        ['dispatch.deadlock_retry_delay_ms', 1],
      ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('sm_ledger.settings')
      ->willReturn($config);

    $service = new LedgerDispatchService(
      $projection,
      $connection,
      $lock,
      $bus,
      $configFactory,
    );

    static::assertTrue($service->recordAndDispatch(
      $source,
      'update',
      'dedupe-key',
      new \stdClass(),
    ));
    static::assertSame(1, $transactionOne->rollbacks);
    static::assertSame(0, $transactionTwo->rollbacks);
  }

  /**
   * Tests wrapped PostgreSQL and MySQL transient failures are retried.
   *
   * @dataProvider retryableDriverExceptionProvider
   */
  public function testRecordAndDispatchRetriesWrappedDriverExceptions(
    \Throwable $driverException,
  ): void {
    $source = $this->createMock(EntityInterface::class);
    $record = $this->createMock(EventRecordInterface::class);
    $projection = $this->createMock(LedgerProjectionService::class);
    $recordQueuedAttempts = 0;
    $projection->expects($this->exactly(2))
      ->method('findRecentByDedupeKey')
      ->with('dedupe-key')
      ->willReturn(0);
    $projection->expects($this->exactly(2))
      ->method('recordQueuedEvent')
      ->willReturnCallback(function () use ($record, $driverException, &$recordQueuedAttempts): EventRecordInterface {
        $recordQueuedAttempts++;
        if ($recordQueuedAttempts === 1) {
          throw new \RuntimeException('Dispatch failed.', 0, $driverException);
        }

        return $record;
      });

    $connection = $this->createMock(Connection::class);
    $connection->expects($this->exactly(2))
      ->method('startTransaction')
      ->willReturnOnConsecutiveCalls(
        new class() {

          /**
           * Records a rollback.
           */
          public function rollBack(): void {}

        },
        new class() {

          /**
           * Records a rollback.
           */
          public function rollBack(): void {}

        },
      );

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release')->willReturn(TRUE);

    $bus = $this->createMock(MessageBusInterface::class);
    $bus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

    $service = new LedgerDispatchService(
      $projection,
      $connection,
      $lock,
      $bus,
      $this->createConfigFactory(),
    );

    static::assertTrue($service->recordAndDispatch(
      $source,
      'update',
      'dedupe-key',
      new \stdClass(),
    ));
  }

  /**
   * Provides retryable wrapped PostgreSQL/MySQL transaction failures.
   *
   * @return iterable<string, array{0: \Throwable}>
   *   Retryable driver failures.
   */
  public static function retryableDriverExceptionProvider(): iterable {
    yield 'postgres deadlock sqlstate' => [new \RuntimeException('SQLSTATE[40P01]: deadlock detected')];

    yield 'postgres serialization message' => [new \RuntimeException('could not serialize access due to concurrent update')];

    yield 'mysql deadlock sqlstate in message' => [new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')];

    yield 'mysql lock wait vendor code' => [new \RuntimeException('Lock wait timeout exceeded', 1205)];
  }

  /**
   * Creates the shared ledger settings config factory double.
   */
  private function createConfigFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnMap([
        ['dispatch.deadlock_retry_attempts', 2],
        ['dispatch.deadlock_retry_delay_ms', 1],
      ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('sm_ledger.settings')
      ->willReturn($config);

    return $configFactory;
  }

}
