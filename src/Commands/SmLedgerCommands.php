<?php

namespace Drupal\sm_ledger\Commands;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\LedgerRetentionService;
use Drupal\sm_ledger\Service\LedgerArchiveService;
use Drupal\sm_ledger\Service\LedgerOperatorService;
use Drupal\sm_ledger\Service\LedgerStaleClaimService;
use Drupal\sm_workers\Service\TransportQueueDepthService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

/**
 * Drush commands for SM Ledger operations.
 */
class SmLedgerCommands extends DrushCommands {

  /**
   * Ledger record storage.
   */
  private EntityStorageInterface $recordStorage;

  /**
   * Constructs the command class.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private LedgerRetentionService $retention,
    private LedgerArchiveService $archive,
    private LedgerStaleClaimService $staleClaims,
    private LedgerOperatorService $operator,
    private TransportQueueDepthService $queueDepths,
  ) {
    $this->recordStorage = $entityTypeManager->getStorage('event_record');
  }

  /**
   * Shows or applies ledger retention pruning.
   */
  #[CLI\Command(name: 'sm-ledger:prune', aliases: ['sml:prune'])]
  #[CLI\Help(description: 'Prune old completed or abandoned ledger rows using the configured retention policy.')]
  #[CLI\Option(name: 'apply', description: 'Delete matching rows. Without this option, the command is a dry run.')]
  #[CLI\Option(name: 'limit', description: 'Maximum rows to delete per status in one run.')]
  public function prune(
    array $options = [
      'apply' => FALSE,
      'limit' => 500,
    ],
  ): void {
    $policies = $this->retention->getConfiguredPolicies();
    $summary = $this->retention->summarizeConfiguredCandidates();

    $this->output()->writeln('<info>SM Ledger retention policy</info>');
    foreach ($policies as $status => $days) {
      $this->output()->writeln(sprintf(
        '  %s: %d day(s)',
        $status,
        $days
      ));
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Matching rows</info>');
    foreach ($summary as $status => $count) {
      $this->output()->writeln(sprintf('  %s: %d', $status, $count));
    }

    if (!(bool) $options['apply']) {
      $this->output()->writeln('');
      $this->output()->writeln('<comment>Dry run only. Re-run with --apply to delete matching rows.</comment>');
      return;
    }

    $deleted = $this->retention->pruneConfigured((int) ($options['limit'] ?? 500));
    $this->output()->writeln('');
    $this->output()->writeln('<info>Deleted rows</info>');
    foreach ($deleted as $status => $count) {
      $this->output()->writeln(sprintf('  %s: %d', $status, $count));
    }
  }

  /**
   * Exports old ledger rows to NDJSON and optionally prunes them.
   */
  #[CLI\Command(name: 'sm-ledger:archive', aliases: ['sml:archive'])]
  #[CLI\Help(description: 'Archive retention-matching ledger rows to newline-delimited JSON.')]
  #[CLI\Option(name: 'path', description: 'Output file path. Defaults to ./sm-ledger-archive-YYYYmmddHHMMSS.ndjson')]
  #[CLI\Option(
    name: 'apply-prune',
    description: 'Delete archived rows after export. Without this option, '
    . 'the command only exports.',
  )]
  #[CLI\Option(name: 'limit', description: 'Maximum rows to export per status in one run.')]
  public function archive(
    array $options = [
      'path' => '',
      'apply-prune' => FALSE,
      'limit' => 500,
    ],
  ): void {
    $path = (string) ($options['path'] ?: ('sm-ledger-archive-' . gmdate('YmdHis') . '.ndjson'));
    $exported = $this->archive->archiveConfigured(
      $path,
      (int) ($options['limit'] ?? 500),
      (bool) $options['apply-prune'],
    );

    $this->output()->writeln(sprintf('<info>Archive file:</info> %s', $path));
    $this->output()->writeln('<info>Exported rows</info>');
    foreach ($exported as $status => $count) {
      $this->output()->writeln(sprintf('  %s: %d', $status, $count));
    }

    if (!(bool) $options['apply-prune']) {
      $this->output()->writeln('');
      $this->output()->writeln('<comment>Export only. Re-run with --apply-prune to delete archived rows.</comment>');
    }
  }

  /**
   * Shows or requeues stale in-progress ledger rows.
   */
  #[CLI\Command(name: 'sm-ledger:stale', aliases: ['sml:stale'])]
  #[CLI\Help(description: 'Inspect or requeue stale in-progress ledger rows.')]
  #[CLI\Option(name: 'threshold', description: 'Age in seconds after which an in-progress row is considered stale.')]
  #[CLI\Option(name: 'limit', description: 'Maximum rows to inspect or requeue in one run.')]
  #[CLI\Option(
    name: 'apply-requeue',
    description: 'Requeue matching stale rows. Without this option, the '
    . 'command is a dry run.',
  )]
  public function stale(
    array $options = [
      'threshold' => NULL,
      'limit' => 100,
      'apply-requeue' => FALSE,
    ],
  ): void {
    $threshold = isset($options['threshold']) && $options['threshold'] !== NULL
      ? max(1, (int) $options['threshold'])
      : NULL;
    $limit = max(1, (int) ($options['limit'] ?? 100));
    $records = $this->staleClaims->loadStaleClaims($threshold, $limit);

    $this->output()->writeln(sprintf(
      '<info>Stale claims older than %d second(s)</info>',
      $threshold ?? $this->staleClaims->getThresholdSeconds(),
    ));
    $this->output()->writeln(sprintf(
      'Total stale rows: %d',
      $this->staleClaims->countStaleClaims($threshold),
    ));

    if ($records === []) {
      $this->output()->writeln('No stale rows found.');
      return;
    }

    $rows = [];
    foreach ($records as $record) {
      $rows[] = [
        'id' => (int) $record->id(),
        'event_kind' => (string) $record->get('event_kind')->value,
        'queue' => (string) $record->get('queue_name')->value,
        'attempts' => (int) $record->get('attempt_count')->value,
        'last_started_at' => (int) $record->get('last_started_at')->value,
      ];
    }
    $this->io()->table(
      ['ID', 'Kind', 'Queue', 'Attempts', 'Last started'],
      $rows,
    );

    if (!(bool) $options['apply-requeue']) {
      $this->output()->writeln('');
      $this->output()->writeln(
        '<comment>Dry run only. Re-run with --apply-requeue to requeue these rows.</comment>',
      );
      return;
    }

    $requeued = $this->staleClaims->requeueStaleClaims($threshold, $limit);
    $this->output()->writeln('');
    $this->output()->writeln(sprintf(
      '<info>Requeued %d stale row(s).</info>',
      count($requeued),
    ));
  }

  /**
   * Drains a transport, requeues stranded ledger rows, and drains again.
   */
  #[CLI\Command(name: 'sm-ledger:drain', aliases: ['sml:drain'])]
  #[CLI\Help(description: 'Consume one Messenger transport until empty, requeue matching stranded ledger rows, then consume the same transport again. This gives operators one command for both live transport work and ledger-only recovery.')]
  #[CLI\Option(name: 'transport', description: 'Required Messenger transport name to consume, such as islandora_index_fedora.')]
  #[CLI\Option(name: 'limit', description: 'Maximum matching ledger rows to requeue.')]
  #[CLI\Option(name: 'time-limit', description: 'Messenger consume time limit in seconds for each drain pass.')]
  #[CLI\Option(name: 'status', description: 'Optional comma-separated ledger statuses to requeue. Defaults to failed,abandoned,retry_due,queued after the first drain pass.')]
  #[CLI\Option(name: 'include-queued', description: 'Deprecated no-op. Queued rows are requeued by default after the first drain pass.')]
  #[CLI\Option(name: 'event-kind', description: 'Optional event-kind filter such as indexing or derivative.')]
  #[CLI\Option(name: 'target', description: 'Optional target_system filter.')]
  #[CLI\Option(name: 'queue', description: 'Optional queue_name filter.')]
  public function drain(
    array $options = [
      'transport' => '',
      'limit' => 100,
      'time-limit' => 3600,
      'status' => '',
      'include-queued' => TRUE,
      'event-kind' => '',
      'target' => '',
      'queue' => '',
    ],
  ): int {
    $transport = trim((string) ($options['transport'] ?? ''));
    if ($transport === '') {
      throw new \InvalidArgumentException('The --transport option is required.');
    }

    $limit = max(1, (int) ($options['limit'] ?? 100));
    $timeLimit = max(1, (int) ($options['time-limit'] ?? 3600));
    $statuses = $this->resolveStatuses(
      (string) ($options['status'] ?? ''),
      TRUE,
    );

    $this->output()->writeln(sprintf('<info>Draining transport %s</info>', $transport));
    $firstConsumeExitCode = $this->consumeTransport($transport, $timeLimit);

    $records = $this->loadRequeueCandidates(
      $statuses,
      $limit,
      trim((string) ($options['event-kind'] ?? '')),
      trim((string) ($options['target'] ?? '')),
      trim((string) ($options['queue'] ?? '')),
    );

    if ($records === []) {
      $this->output()->writeln('No matching stranded ledger rows found.');
      return $firstConsumeExitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    $requeued = 0;
    $failed = 0;
    foreach ($records as $record) {
      $recordId = (int) $record->id();
      if (!$this->operator->canRequeue($record)) {
        $this->output()->writeln(sprintf(
          'SKIPPED [record=%d kind=%s target=%s queue=%s status=%s] No requeue handler matched.',
          $recordId,
          (string) $record->get('event_kind')->value,
          (string) $record->get('target_system')->value,
          (string) $record->get('queue_name')->value,
          (string) $record->get('status')->value,
        ));
        continue;
      }

      try {
        $this->operator->requeue($record);
        ++$requeued;
        $this->output()->writeln(sprintf(
          'REQUEUED [record=%d kind=%s target=%s queue=%s status=%s]',
          $recordId,
          (string) $record->get('event_kind')->value,
          (string) $record->get('target_system')->value,
          (string) $record->get('queue_name')->value,
          (string) $record->get('status')->value,
        ));
      }
      catch (\Throwable $exception) {
        ++$failed;
        $this->output()->writeln(sprintf(
          'FAILED [record=%d kind=%s target=%s queue=%s status=%s] %s',
          $recordId,
          (string) $record->get('event_kind')->value,
          (string) $record->get('target_system')->value,
          (string) $record->get('queue_name')->value,
          (string) $record->get('status')->value,
          $exception->getMessage(),
        ));
      }
    }

    $this->output()->writeln(sprintf(
      '<info>Second drain pass for transport %s after requeueing %d record(s)</info>',
      $transport,
      $requeued,
    ));
    $secondConsumeExitCode = $this->consumeTransport($transport, $timeLimit);

    return ($firstConsumeExitCode === 0 && $secondConsumeExitCode === 0 && $failed === 0)
      ? Command::SUCCESS
      : Command::FAILURE;
  }

  /**
   * Resolves the ledger statuses to requeue.
   *
   * @return list<string>
   *   Normalized status list.
   */
  private function resolveStatuses(string $statusOption, bool $includeQueued): array {
    $statuses = array_values(array_filter(array_map('trim', explode(',', $statusOption))));
    if ($statuses === []) {
      $statuses = [
        EventRecord::STATUS_FAILED,
        EventRecord::STATUS_ABANDONED,
        EventRecord::STATUS_RETRY_DUE,
        EventRecord::STATUS_QUEUED,
      ];
    }

    return $statuses;
  }

  /**
   * Loads candidate ledger rows for generic requeue.
   *
   * @return list<\Drupal\sm_ledger\Entity\EventRecordInterface>
   *   Matching ledger rows.
   */
  private function loadRequeueCandidates(
    array $statuses,
    int $limit,
    string $eventKind,
    string $target,
    string $queue,
  ): array {
    $query = $this->recordStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('needs_processing', 1)
      ->condition('status', $statuses, 'IN')
      ->sort('id', 'ASC')
      ->range(0, $limit);

    if ($eventKind !== '') {
      $query->condition('event_kind', $eventKind);
    }
    if ($target !== '') {
      $query->condition('target_system', $target);
    }
    if ($queue !== '') {
      $query->condition('queue_name', $queue);
    }

    $records = [];
    foreach ($this->recordStorage->loadMultiple($query->execute()) as $record) {
      if ($record instanceof EventRecordInterface) {
        $records[] = $record;
      }
    }

    return $records;
  }

  /**
   * Consumes one Messenger transport until empty using the sm binary.
   */
  private function consumeTransport(string $transport, int $timeLimit): int {
    $projectRoot = dirname((string) DRUPAL_ROOT);
    $binary = $projectRoot . '/vendor/bin/sm';
    if (!is_file($binary)) {
      throw new \RuntimeException(sprintf(
        'Symfony Messenger binary was not found at %s.',
        $binary,
      ));
    }

    if ($this->supportsStopWhenEmpty($binary, $projectRoot)) {
      $process = new Process([
        $binary,
        'messenger:consume',
        $transport,
        '--time-limit=' . $timeLimit,
        '--stop-when-empty',
      ], $projectRoot);
      $process->setTimeout(NULL);
      $process->run(function (string $type, string $buffer): void {
        $this->output()->write($buffer);
      });

      return $process->getExitCode() ?? Command::FAILURE;
    }

    return $this->consumeTransportByDepth($binary, $projectRoot, $transport, $timeLimit);
  }

  /**
   * Consumes a transport in bounded batches for older sm binaries.
   */
  private function consumeTransportByDepth(
    string $binary,
    string $projectRoot,
    string $transport,
    int $timeLimit,
  ): int {
    $exitCode = Command::SUCCESS;
    $lastDepth = NULL;

    while (TRUE) {
      $depth = $this->currentQueueDepth($transport);
      if ($depth <= 0) {
        return $exitCode;
      }

      if ($lastDepth !== NULL && $depth >= $lastDepth) {
        $this->output()->writeln(sprintf(
          '<comment>Transport %s depth did not decrease (current depth=%d). Stopping compatibility drain loop.</comment>',
          $transport,
          $depth,
        ));
        return Command::FAILURE;
      }
      $lastDepth = $depth;

      $process = new Process([
        $binary,
        'messenger:consume',
        $transport,
        '--time-limit=' . $timeLimit,
        '--limit=' . $depth,
      ], $projectRoot);
      $process->setTimeout(NULL);
      $process->run(function (string $type, string $buffer): void {
        $this->output()->write($buffer);
      });

      $exitCode = $process->getExitCode() ?? Command::FAILURE;
      if ($exitCode !== 0) {
        return $exitCode;
      }
    }
  }

  /**
   * Returns whether the installed sm binary supports --stop-when-empty.
   */
  private function supportsStopWhenEmpty(string $binary, string $projectRoot): bool {
    $process = new Process([
      $binary,
      'messenger:consume',
      '--help',
    ], $projectRoot);
    $process->setTimeout(NULL);
    $process->run();

    return str_contains($process->getOutput() . $process->getErrorOutput(), '--stop-when-empty');
  }

  /**
   * Returns the current SQL-backed queue depth for one transport.
   */
  private function currentQueueDepth(string $transport): int {
    $depths = $this->queueDepths->queueDepths();
    return max(0, (int) ($depths[$transport] ?? 0));
  }

}
