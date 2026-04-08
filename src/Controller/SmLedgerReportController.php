<?php

namespace Drupal\sm_ledger\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the SM Ledger reports page.
 */
class SmLedgerReportController extends ControllerBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The event record base table.
   */
  protected string $baseTable;

  /**
   * Shared circuit breaker state service.
   */
  protected CircuitBreakerService $circuitBreakers;

  /**
   * Constructs a report controller.
   */
  public function __construct(
    Connection $database,
    DateFormatterInterface $dateFormatter,
    EntityTypeManagerInterface $entityTypeManager,
    CircuitBreakerService $circuitBreakers,
  ) {
    $this->database = $database;
    $this->dateFormatter = $dateFormatter;
    $this->circuitBreakers = $circuitBreakers;
    $this->baseTable = (string) $entityTypeManager
      ->getDefinition('event_record')
      ->getBaseTable();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('sm_workers.circuit_breaker'),
    );
  }

  /**
   * Builds the report page.
   */
  public function overview(): array {
    return [
      'operator_help' => $this->buildOperatorHelpSection(),
      'summary' => $this->buildSummarySection(),
      'circuit_breakers' => $this->buildCircuitBreakersSection(),
      'records' => $this->buildRecordsViewSection(),
      'pending' => $this->buildPendingSection(),
      'processing' => $this->buildAverageProcessingSection(),
      'failures' => $this->buildFailuresSection(),
    ];
  }

  /**
   * Builds a short operator guidance section.
   */
  protected function buildOperatorHelpSection(): array {
    return [
      'title' => [
        '#markup' => '<h2>' . $this->t('How To Use This Page') . '</h2>',
      ],
      'items' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Use ledger records to answer what happened, when it happened, and which source entity was affected.'),
          $this->t('Use worker and downstream service logs to answer why a failure happened.'),
          $this->t('Before requeueing failed work, inspect the recent failure details here and then confirm the underlying worker or downstream issue is resolved.'),
        ],
      ],
    ];
  }

  /**
   * Builds high-level status counts.
   */
  protected function buildSummarySection(): array {
    $query = $this->database->select($this->baseTable, 'ier');
    $query->fields('ier', ['status']);
    $query->addExpression('COUNT(*)', 'event_count');
    $query->groupBy('ier.status');
    $query->orderBy('ier.status');

    $rows = [];
    $statusCounts = [];
    foreach ($query->execute() as $record) {
      $count = (int) $record->event_count;
      $statusCounts[(string) $record->status] = $count;
      $rows[] = [
        'data' => [
          $this->humanizeMachineName((string) $record->status),
          $count,
        ],
      ];
    }

    $pendingTotal = array_sum($this->fetchPendingCounts());
    $manualTotal = $this->database->select($this->baseTable, 'ier')
      ->condition('ier.requires_manual_intervention', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $overviewItems = [
      $this->t('Pending events: @count', ['@count' => $pendingTotal]),
      $this->t('Failed events: @count', ['@count' => $statusCounts['failed'] ?? 0]),
      $this->t('Abandoned events: @count', ['@count' => $statusCounts['abandoned'] ?? 0]),
      $this->t('Events requiring manual intervention: @count', ['@count' => (int) $manualTotal]),
    ];

    return [
      'title' => [
        '#markup' => '<h2>' . $this->t('Overview') . '</h2>',
      ],
      'totals' => [
        '#theme' => 'item_list',
        '#items' => $overviewItems,
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Status'), $this->t('Count')],
        '#rows' => $rows,
        '#empty' => $this->t('No ledger records found.'),
      ],
    ];
  }

  /**
   * Builds pending counts grouped by generic operation label.
   */
  protected function buildPendingSection(): array {
    $counts = $this->fetchPendingCounts();
    $rows = [];

    foreach ($counts as $actionPluginId => $count) {
      $rows[] = [
        'data' => [
          $actionPluginId === '' ? $this->t('(none)') : $actionPluginId,
          $count,
        ],
      ];
    }

    return [
      'title' => [
        '#markup' => '<h2>' . $this->t('Pending By Operation') . '</h2>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Operation'), $this->t('Pending events')],
        '#rows' => $rows,
        '#empty' => $this->t('No pending events found.'),
      ],
    ];
  }

  /**
   * Builds a circuit breaker status summary.
   */
  protected function buildCircuitBreakersSection(): array {
    $rows = [];

    foreach ($this->circuitBreakers->all() as $breakerId => $breaker) {
      $status = (string) ($breaker['status'] ?? 'closed');
      $openUntil = (int) ($breaker['open_until'] ?? 0);
      $rows[] = [
        'data' => [
          (string) ($breaker['label'] ?? $breakerId),
          $this->humanizeMachineName($status),
          $status === 'open' && $openUntil > 0 ? $this->formatIsoDate($openUntil) : '-',
          $this->truncateText((string) ($breaker['last_error'] ?? '')),
          Link::fromTextAndUrl(
            $this->t('Manage'),
            Url::fromRoute('sm_workers.circuit_breakers'),
          ),
        ],
      ];
    }

    return [
      'title' => [
        '#markup' => '<h2>' . $this->t('Circuit Breakers') . '</h2>',
      ],
      'help' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['description']],
        'text' => [
          '#markup' => $this->t('Open breakers mean workers are intentionally refusing outbound requests for a downstream integration. Resolve the endpoint issue before retrying queued work.'),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Breaker'),
          $this->t('Status'),
          $this->t('Open until'),
          $this->t('Last error'),
          $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No circuit breakers have been registered yet.'),
      ],
    ];
  }

  /**
   * Fetches pending counts grouped by generic operation label.
   *
   * @return array<string, int>
   *   Counts keyed by operation label.
   */
  protected function fetchPendingCounts(): array {
    $operationExpression = $this->getOperationGroupingExpression('ier');
    $query = $this->database->select($this->baseTable, 'ier');
    $query->addExpression($operationExpression, 'operation_label');
    $query->addExpression('COUNT(*)', 'event_count');
    $query->condition('ier.needs_processing', 1);
    $query->groupBy('operation_label');
    $query->orderBy('event_count', 'DESC');
    $query->orderBy('operation_label');

    $counts = [];
    foreach ($query->execute() as $record) {
      $counts[(string) $record->operation_label] = (int) $record->event_count;
    }

    return $counts;
  }

  /**
   * Builds average processing times grouped by generic operation label.
   */
  protected function buildAverageProcessingSection(): array {
    $operationExpression = $this->getOperationGroupingExpression('ier');
    $query = $this->database->select($this->baseTable, 'ier');
    $query->addExpression($operationExpression, 'operation_label');
    $query->addExpression('COUNT(*)', 'event_count');
    $query->addExpression('AVG(ier.completed_at - ier.first_started_at)', 'average_seconds');
    $query->condition('ier.status', 'completed');
    $query->condition('ier.first_started_at', 0, '>');
    $query->condition('ier.completed_at', 0, '>');
    $query->where('ier.completed_at >= ier.first_started_at');
    $query->groupBy('operation_label');
    $query->orderBy('average_seconds', 'DESC');
    $query->orderBy('operation_label');

    $rows = [];
    foreach ($query->execute() as $record) {
      $averageSeconds = (float) $record->average_seconds;
      $rows[] = [
        'data' => [
          (string) $record->operation_label,
          (int) $record->event_count,
          $this->formatDuration($averageSeconds),
        ],
      ];
    }

    return [
      'title' => [
        '#markup' => '<h2>' . $this->t('Average Processing Time By Operation') . '</h2>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Operation'),
          $this->t('Completed events'),
          $this->t('Average processing time'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No completed events with timing data found.'),
      ],
    ];
  }

  /**
   * Builds the embedded ledger records view section.
   */
  protected function buildRecordsViewSection(): array {
    return [
      'title' => [
        '#markup' => '<h2>' . $this->t('Ledger Records') . '</h2>',
      ],
      'help' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['description']],
        'text' => [
          '#markup' => $this->t('Use the exposed filters to narrow the live ledger records without leaving this report page.'),
        ],
      ],
      'view' => [
        '#type' => 'view',
        '#name' => 'sm_ledger_records',
        '#display_id' => 'block_sm_ledger_records',
        '#embed' => TRUE,
      ],
    ];
  }

  /**
   * Builds a recent failures table.
   */
  protected function buildFailuresSection(): array {
    $query = $this->database->select($this->baseTable, 'ier');
    $query->fields('ier', [
      'id',
      'status',
      'action_plugin_id',
      'trigger_event_type',
      'target_system',
      'source_entity_type',
      'source_entity_id',
      'last_error',
      'attempt_count',
      'changed',
    ]);
    $query->condition('ier.status', ['failed', 'abandoned'], 'IN');
    $query->orderBy('ier.changed', 'DESC');
    $query->range(0, 50);

    $rows = [];
    foreach ($query->execute() as $record) {
      $eventUrl = Url::fromRoute('entity.event_record.canonical', [
        'event_record' => $record->id,
      ]);
      $source = trim(sprintf('%s:%s', $record->source_entity_type, $record->source_entity_id), ':');
      $error = $record->last_error;

      $rows[] = [
        'data' => [
          Link::fromTextAndUrl((string) $record->id, $eventUrl),
          $this->humanizeMachineName((string) $record->status),
          $this->resolveOperationLabel(
            (string) $record->action_plugin_id,
            (string) $record->trigger_event_type,
            (string) $record->target_system,
          ),
          $source !== '' ? $source : $this->t('(none)'),
          $this->truncateText((string) $error),
          (int) $record->attempt_count,
          $this->formatIsoDate((int) $record->changed),
        ],
      ];
    }

    return [
      'title' => [
        '#markup' => '<h2>' . $this->t('Recent Failures') . '</h2>',
      ],
      'help' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['description']],
        'text' => [
          '#markup' => $this->t('These records tell you what failed. Check worker logs and downstream service logs for the same time window to find the underlying exception or service failure.'),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Event'),
          $this->t('Status'),
          $this->t('Operation'),
          $this->t('Source'),
          $this->t('Error'),
          $this->t('Attempts'),
          $this->t('Updated'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No failed or abandoned events found.'),
      ],
    ];
  }

  /**
   * Formats seconds as a compact duration string.
   */
  protected function formatDuration(float $seconds): string {
    if ($seconds < 1) {
      return $this->t('< 1 second')->render();
    }
    return $this->dateFormatter->formatInterval((int) round($seconds));
  }

  /**
   * Formats timestamp as ISO date or dash.
   */
  protected function formatIsoDate(int $timestamp): string {
    return $timestamp > 0 ? gmdate(DATE_ATOM, $timestamp) : '-';
  }

  /**
   * Humanizes a machine-name string.
   */
  protected function humanizeMachineName(string $value): string {
    return ucwords(str_replace('_', ' ', $value));
  }

  /**
   * Returns the SQL expression used to group records by generic operation.
   */
  protected function getOperationGroupingExpression(string $alias): string {
    return sprintf(
      "COALESCE(NULLIF(%s.action_plugin_id, ''), NULLIF(%s.trigger_event_type, ''), NULLIF(%s.target_system, ''), '(none)')",
      $alias,
      $alias,
      $alias,
    );
  }

  /**
   * Resolves a human-readable generic operation label.
   */
  protected function resolveOperationLabel(
    string $actionPluginId,
    string $triggerEventType,
    string $targetSystem,
  ): string {
    foreach ([$actionPluginId, $triggerEventType, $targetSystem] as $candidate) {
      if ($candidate !== '') {
        return $candidate;
      }
    }

    return (string) $this->t('(none)');
  }

  /**
   * Truncates text for compact report tables.
   */
  protected function truncateText(string $text, int $limit = 120): string {
    $text = trim($text);
    if (mb_strlen($text) <= $limit) {
      return $text;
    }
    return mb_substr($text, 0, $limit - 1) . '…';
  }

}
