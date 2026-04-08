<?php

declare(strict_types=1);

namespace Drupal\Tests\sm_ledger\Kernel;

use Drupal\sm_ledger\Service\LedgerAnalyticsService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\EventRecordService;
use Drupal\sm_ledger\Service\LedgerArchiveService;
use Drupal\sm_ledger\Service\LedgerCapacityReportService;
use Drupal\sm_ledger\Service\LedgerDispatchService;
use Drupal\sm_ledger\Service\LedgerOperatorService;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Drupal\sm_ledger\Service\LedgerRecoveryService;
use Drupal\sm_ledger\Service\LedgerRetentionService;
use Drupal\sm_ledger\Service\LedgerStaleClaimService;
use Drupal\sm_workers\Service\TransportQueueDepthService;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for the standalone SM ledger module.
 */
#[RunTestsInSeparateProcesses]
final class SmLedgerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'options',
    'sm',
    'sm_ledger',
    'sm_workers',
    'system',
    'user',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('event_record');
    $this->container->get('config.factory')
      ->getEditable('sm_ledger.settings')
      ->set('retention.completed_days', 30)
      ->set('retention.abandoned_days', 30)
      ->set('retention.failed_days', 0)
      ->set('dedupe.ttl_seconds', 86400)
      ->set('recovery.stale_claim_threshold_seconds', 3600)
      ->save();
  }

  /**
   * Tests service aliases and entity field definitions owned by sm_ledger.
   */
  public function testServiceAliasesAndEntityDefinitionsAreResolvable(): void {
    $container = \Drupal::getContainer();

    static::assertSame(
      $container->get('sm_ledger.event_record'),
      $container->get(EventRecordService::class),
    );
    static::assertSame(
      $container->get('sm_ledger.projection'),
      $container->get(LedgerProjectionService::class),
    );
    static::assertSame(
      $container->get('sm_ledger.retention'),
      $container->get(LedgerRetentionService::class),
    );
    static::assertSame(
      $container->get('sm_ledger.archive'),
      $container->get(LedgerArchiveService::class),
    );
    static::assertSame(
      $container->get('sm_ledger.recovery'),
      $container->get(LedgerRecoveryService::class),
    );
    static::assertSame(
      $container->get('sm_ledger.analytics'),
      $container->get(LedgerAnalyticsService::class),
    );
    static::assertSame(
      $container->get('sm_ledger.capacity_report'),
      $container->get(LedgerCapacityReportService::class),
    );
    static::assertSame(
      $container->get('sm_ledger.dispatch'),
      $container->get(LedgerDispatchService::class),
    );
    static::assertSame(
      $container->get('sm_ledger.operator'),
      $container->get(LedgerOperatorService::class),
    );
    static::assertSame(
      $container->get('sm_workers.queue_depths'),
      $container->get(TransportQueueDepthService::class),
    );

    $definitions = EventRecord::baseFieldDefinitions(
      $this->container->get('entity_type.manager')->getDefinition('event_record'),
    );

    static::assertSame(
      EventRecord::getStatusOptions(),
      $definitions['status']->getSetting('allowed_values'),
    );
    static::assertSame(
      EventRecord::getEventKindOptions(),
      $definitions['event_kind']->getSetting('allowed_values'),
    );
    static::assertSame(
      EventRecord::getTransportModeOptions(),
      $definitions['transport_mode']->getSetting('allowed_values'),
    );
    static::assertArrayHasKey('queue_name', $definitions);
    static::assertArrayHasKey('dedupe_key', $definitions);
    static::assertArrayHasKey('correlation_key', $definitions);
  }

  /**
   * Tests ledger lifecycle transitions and lookup behavior.
   */
  public function testProjectionLifecycleAndLookupMethods(): void {
    $projection = $this->container->get(LedgerProjectionService::class);
    $source = $this->createSourceUser();

    $record = $projection->recordQueuedEvent($source, 'update', [
      'action_plugin_id' => 'index',
      'dedupe_key' => 'index:user:' . $source->id() . ':update',
      'event_kind' => EventRecord::KIND_INDEXING,
      'target_system' => 'fedora',
      'payload_json' => '{"entity_id":1}',
    ]);

    static::assertSame(
      'index:user:' . $source->id() . ':update',
      $record->get('dedupe_key')->value,
    );
    static::assertSame(
      'index:user:' . $source->id() . ':update',
      $record->get('correlation_key')->value,
    );
    static::assertSame('', (string) $record->get('queue_name')->value);
    static::assertSame(
      (int) $record->id(),
      $projection->findOpenEventRecordId('user', (int) $source->id(), 'update'),
    );
    static::assertSame(
      (int) $record->id(),
      $projection->findOpenByCorrelationKey('index:user:' . $source->id() . ':update'),
    );
    static::assertSame(
      (int) $record->id(),
      $projection->findOpenByDedupeKey('index:user:' . $source->id() . ':update'),
    );
    static::assertTrue($projection->isQueuedForProcessing((int) $record->id()));

    $projection->markProcessing((int) $record->id());
    $processing = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_IN_PROGRESS, $processing->get('status')->value);
    static::assertSame('1', (string) $processing->get('attempt_count')->value);
    static::assertNotSame('0', (string) $processing->get('first_started_at')->value);

    $projection->markRetryDue((int) $record->id(), 2, 1234567890, 'Retry scheduled.');
    $retryDue = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_RETRY_DUE, $retryDue->get('status')->value);
    static::assertSame('2', (string) $retryDue->get('retry_count')->value);
    static::assertSame('1234567890', (string) $retryDue->get('next_attempt_at')->value);

    $projection->markFailed((int) $record->id(), 'Terminal failure.');
    $failed = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_FAILED, $failed->get('status')->value);
    static::assertSame('0', (string) $failed->get('needs_processing')->value);
    static::assertSame(
      0,
      $projection->findOpenByDedupeKey('index:user:' . $source->id() . ':update'),
    );

    $projection->markNeedsManualIntervention((int) $record->id(), 'Retries exhausted.');
    $manual = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_FAILED, $manual->get('status')->value);
    static::assertSame('0', (string) $manual->get('needs_processing')->value);
    static::assertSame('1', (string) $manual->get('requires_manual_intervention')->value);

    $projection->markQueuedForManualRetry((int) $record->id());
    $requeued = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_QUEUED, $requeued->get('status')->value);
    static::assertSame('1', (string) $requeued->get('needs_processing')->value);
    static::assertSame('0', (string) $requeued->get('requires_manual_intervention')->value);

    $projection->markCompleted((int) $record->id());
    $completed = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_COMPLETED, $completed->get('status')->value);
    static::assertFalse($projection->isQueuedForProcessing((int) $record->id()));
    static::assertSame(
      0,
      $projection->findOpenByDedupeKey('index:user:' . $source->id() . ':update'),
    );

    $abandoned = $projection->recordQueuedEvent($source, 'delete', [
      'action_plugin_id' => 'index',
      'dedupe_key' => 'index:user:' . $source->id() . ':delete',
      'queue_name' => 'test-queue',
    ]);
    static::assertSame('test-queue', $abandoned->get('queue_name')->value);
    $projection->markAbandoned((int) $abandoned->id(), 'Entity was removed.');
    $abandonedRecord = $this->loadRecord((int) $abandoned->id());
    static::assertSame(EventRecord::STATUS_ABANDONED, $abandonedRecord->get('status')->value);
    static::assertSame('0', (string) $abandonedRecord->get('needs_processing')->value);
  }

  /**
   * Tests failed records can be completed by manual run-now flows.
   */
  public function testFailedRecordCanBeProcessedAndCompletedManually(): void {
    $projection = $this->container->get(LedgerProjectionService::class);
    $source = $this->createSourceUser();

    $record = $projection->recordQueuedEvent($source, 'update', [
      'action_plugin_id' => 'index',
      'dedupe_key' => 'manual-run:user:' . $source->id() . ':update',
      'event_kind' => EventRecord::KIND_INDEXING,
      'target_system' => 'fedora',
      'payload_json' => '{"entity_id":1}',
    ]);

    $projection->markProcessing((int) $record->id());
    $projection->markFailed((int) $record->id(), 'Initial failure.');

    $failed = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_FAILED, $failed->get('status')->value);
    static::assertSame('0', (string) $failed->get('needs_processing')->value);

    $projection->markProcessing((int) $record->id());
    $processing = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_IN_PROGRESS, $processing->get('status')->value);

    $projection->markCompleted((int) $record->id());
    $completed = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_COMPLETED, $completed->get('status')->value);
    static::assertSame('0', (string) $completed->get('needs_processing')->value);
  }

  /**
   * Tests TTL-based dedupe suppression and atomic recovery claiming.
   */
  public function testDedupeTtlAndClaiming(): void {
    $projection = $this->container->get(LedgerProjectionService::class);
    $recovery = $this->container->get(LedgerRecoveryService::class);
    $source = $this->createSourceUser();

    $record = $projection->recordQueuedEvent($source, 'update', [
      'action_plugin_id' => 'index',
      'dedupe_key' => 'claim:user:' . $source->id() . ':update',
      'event_kind' => EventRecord::KIND_DERIVATIVE,
      'queue_name' => 'claim-queue',
    ]);

    $duplicateThrown = FALSE;
    try {
      $projection->recordQueuedEvent($source, 'update', [
        'action_plugin_id' => 'index',
        'dedupe_key' => 'claim:user:' . $source->id() . ':update',
        'event_kind' => EventRecord::KIND_DERIVATIVE,
        'queue_name' => 'claim-queue',
      ]);
    }
    catch (\Throwable) {
      $duplicateThrown = TRUE;
    }

    static::assertTrue($duplicateThrown);

    $this->container->get('database')
      ->update('sm_ledger_event_record')
      ->fields([
        'emitted_at' => \Drupal::time()->getRequestTime() - 86500,
        'changed' => \Drupal::time()->getRequestTime() - 86500,
      ])
      ->condition('id', (int) $record->id())
      ->execute();

    $expiredDuplicate = $projection->recordQueuedEvent($source, 'update', [
      'action_plugin_id' => 'index',
      'dedupe_key' => 'claim:user:' . $source->id() . ':update',
      'event_kind' => EventRecord::KIND_DERIVATIVE,
      'queue_name' => 'claim-queue',
    ]);

    static::assertSame(
      (int) $expiredDuplicate->id(),
      $projection->findRecentByDedupeKey('claim:user:' . $source->id() . ':update'),
    );
    static::assertSame(
      (int) $expiredDuplicate->id(),
      $projection->findRecentByDedupeKey('claim:user:' . $source->id() . ':update', 1),
    );

    $claimedIds = $recovery->claimRecordsForProcessing(
      EventRecord::KIND_DERIVATIVE,
      'claim-queue',
      10,
    );
    static::assertSame([(int) $record->id(), (int) $expiredDuplicate->id()], $claimedIds);

    $claimedRecord = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_IN_PROGRESS, $claimedRecord->get('status')->value);
    static::assertSame('1', (string) $claimedRecord->get('attempt_count')->value);

    static::assertSame([], $recovery->claimRecordsForProcessing(
      EventRecord::KIND_DERIVATIVE,
      'claim-queue',
      10,
    ));

    $projection->markRetryDue((int) $expiredDuplicate->id(), 1, \Drupal::time()->getRequestTime() + 600, 'Retry later.');
    static::assertSame([], $recovery->claimRecordsForProcessing(
      EventRecord::KIND_DERIVATIVE,
      'claim-queue',
      10,
    ));
  }

  /**
   * Tests stale in-progress rows can be detected and requeued.
   */
  public function testStaleClaimDetectionAndRequeue(): void {
    $projection = $this->container->get(LedgerProjectionService::class);
    $staleClaims = $this->container->get(LedgerStaleClaimService::class);
    $source = $this->createSourceUser();

    $record = $projection->recordQueuedEvent($source, 'update', [
      'action_plugin_id' => 'stale-check',
      'dedupe_key' => 'stale:user:' . $source->id() . ':update',
      'event_kind' => EventRecord::KIND_DERIVATIVE,
      'queue_name' => 'stale-queue',
    ]);
    $projection->markProcessing((int) $record->id());

    $staleStartedAt = \Drupal::time()->getRequestTime() - 7200;
    $this->container->get('database')
      ->update('sm_ledger_event_record')
      ->fields([
        'last_started_at' => $staleStartedAt,
        'changed' => $staleStartedAt,
      ])
      ->condition('id', (int) $record->id())
      ->execute();

    static::assertSame(1, $staleClaims->countStaleClaims(3600));
    static::assertCount(1, $staleClaims->loadStaleClaims(3600, 10));
    static::assertSame([(int) $record->id()], $staleClaims->requeueStaleClaims(3600, 10));

    $requeued = $this->loadRecord((int) $record->id());
    static::assertSame(EventRecord::STATUS_QUEUED, $requeued->get('status')->value);
    static::assertSame('1', (string) $requeued->get('needs_processing')->value);
  }

  /**
   * Tests retention and archive behavior owned by sm_ledger.
   */
  public function testRetentionAndArchiveOperateOnLedgerRows(): void {
    $projection = $this->container->get(LedgerProjectionService::class);
    $retention = $this->container->get(LedgerRetentionService::class);
    $archive = $this->container->get(LedgerArchiveService::class);
    $source = $this->createSourceUser();

    $completed = $projection->recordQueuedEvent($source, 'update', [
      'action_plugin_id' => 'archive',
      'dedupe_key' => 'archive:completed',
    ]);
    $projection->markCompleted((int) $completed->id());

    $abandoned = $projection->recordQueuedEvent($source, 'delete', [
      'action_plugin_id' => 'archive',
      'dedupe_key' => 'archive:abandoned',
    ]);
    $projection->markAbandoned((int) $abandoned->id(), 'Not applicable anymore.');

    $failed = $projection->recordQueuedEvent($source, 'update', [
      'action_plugin_id' => 'archive',
      'dedupe_key' => 'archive:failed',
    ]);
    $projection->markFailed((int) $failed->id(), 'Retriable error.');

    $manual = $projection->recordQueuedEvent($source, 'update', [
      'action_plugin_id' => 'archive',
      'dedupe_key' => 'archive:manual',
    ]);
    $projection->markNeedsManualIntervention((int) $manual->id(), 'Manual inspection required.');

    $database = $this->container->get('database');
    $cutoffAge = \Drupal::time()->getRequestTime() - (40 * 86400);
    $database->update('sm_ledger_event_record')
      ->fields([
        'completed_at' => $cutoffAge,
        'changed' => $cutoffAge,
      ])
      ->condition('id', [(int) $completed->id(), (int) $abandoned->id()], 'IN')
      ->execute();
    $database->update('sm_ledger_event_record')
      ->fields(['changed' => $cutoffAge])
      ->condition('id', [(int) $failed->id(), (int) $manual->id()], 'IN')
      ->execute();

    $summary = $retention->summarizeConfiguredCandidates();
    static::assertSame(1, $summary[EventRecord::STATUS_COMPLETED] ?? 0);
    static::assertSame(1, $summary[EventRecord::STATUS_ABANDONED] ?? 0);
    static::assertArrayNotHasKey(EventRecord::STATUS_FAILED, $summary);

    $config = $this->container->get('config.factory')->getEditable('sm_ledger.settings');
    $config->set('retention.failed_days', 30)->save();

    $failedCandidates = $retention->loadCandidates(EventRecord::STATUS_FAILED, 30, 10);
    static::assertCount(1, $failedCandidates);
    static::assertSame((int) $failed->id(), (int) reset($failedCandidates)->id());

    $archivePath = tempnam(sys_get_temp_dir(), 'sm-ledger-archive-');
    static::assertNotFalse($archivePath);

    try {
      $exported = $archive->archiveConfigured($archivePath, 10, TRUE);
      static::assertSame(1, $exported[EventRecord::STATUS_COMPLETED] ?? 0);
      static::assertSame(1, $exported[EventRecord::STATUS_ABANDONED] ?? 0);
      static::assertSame(1, $exported[EventRecord::STATUS_FAILED] ?? 0);

      $lines = file($archivePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      static::assertIsArray($lines);
      static::assertCount(3, $lines);

      foreach ($lines as $line) {
        $decoded = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
        static::assertSame('event_record', $decoded['entity_type'] ?? NULL);
        static::assertArrayHasKey('fields', $decoded);
      }
    }
    finally {
      @unlink($archivePath);
    }

    static::assertNull($this->loadRecordOrNull((int) $completed->id()));
    static::assertNull($this->loadRecordOrNull((int) $abandoned->id()));
    static::assertNull($this->loadRecordOrNull((int) $failed->id()));
    static::assertNotNull($this->loadRecordOrNull((int) $manual->id()));
  }

  /**
   * Creates a persisted source entity for ledger ownership tests.
   */
  private function createSourceUser(): User {
    $user = User::create([
      'name' => 'ledger-source-' . uniqid('', TRUE),
    ]);
    $user->save();
    return $user;
  }

  /**
   * Loads one record and asserts that it exists.
   */
  private function loadRecord(int $id): EventRecordInterface {
    $record = $this->loadRecordOrNull($id);
    static::assertInstanceOf(EventRecordInterface::class, $record);
    return $record;
  }

  /**
   * Loads one record when present.
   */
  private function loadRecordOrNull(int $id): ?EventRecordInterface {
    $record = $this->container->get('entity_type.manager')
      ->getStorage('event_record')
      ->load($id);
    return $record instanceof EventRecordInterface ? $record : NULL;
  }

}
