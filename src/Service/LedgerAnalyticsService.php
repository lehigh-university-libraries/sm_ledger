<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides aggregate analytics over persisted ledger rows.
 */
final class LedgerAnalyticsService {

  /**
   * The event record base table.
   */
  private string $baseTable;

  /**
   * Constructs the analytics service.
   */
  public function __construct(
    private Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->baseTable = (string) $entityTypeManager
      ->getDefinition('event_record')
      ->getBaseTable();
  }

  /**
   * Returns ledger row counts by status.
   *
   * @return array<string, int>
   *   Counts keyed by status.
   */
  public function statusCounts(): array {
    $counts = [];
    $query = $this->database->select($this->baseTable, 'er');
    $query->fields('er', ['status']);
    $query->addExpression('COUNT(*)', 'record_count');
    $query->groupBy('er.status');

    foreach ($query->execute() as $row) {
      $counts[(string) $row->status] = (int) $row->record_count;
    }

    return $counts;
  }

  /**
   * Returns recent completion throughput figures.
   *
   * @return array<string, float|int>
   *   Completion summary.
   */
  public function completionSummary(int $cutoff, int $windowMinutes): array {
    $query = $this->database->select($this->baseTable, 'er');
    $query->addExpression('COUNT(*)', 'completed_count');
    $query->condition('er.status', 'completed');
    $query->condition('er.completed_at', $cutoff, '>=');
    $completedCount = (int) $query->execute()->fetchField();

    $windowSeconds = max(60, $windowMinutes * 60);
    return [
      'completed_count' => $completedCount,
      'completed_per_minute' => round($completedCount / $windowMinutes, 2),
      'completed_rps' => round($completedCount / $windowSeconds, 4),
    ];
  }

  /**
   * Returns recent average processing and queue-wait timing.
   *
   * @return array<string, float|int>
   *   Timing summary.
   */
  public function processingLatencySummary(int $cutoff): array {
    $query = $this->database->select($this->baseTable, 'er');
    $query->addExpression('COUNT(*)', 'sample_count');
    $query->addExpression('AVG(er.completed_at - er.first_started_at)', 'avg_processing_seconds');
    $query->addExpression('AVG(er.first_started_at - er.emitted_at)', 'avg_queue_wait_seconds');
    $query->condition('er.status', 'completed');
    $query->condition('er.completed_at', $cutoff, '>=');
    $query->condition('er.first_started_at', 0, '>');
    $query->condition('er.completed_at', 0, '>');
    $row = $query->execute()->fetchAssoc() ?: [];

    return [
      'sample_count' => (int) ($row['sample_count'] ?? 0),
      'avg_processing_seconds' => round((float) ($row['avg_processing_seconds'] ?? 0), 2),
      'avg_queue_wait_seconds' => round((float) ($row['avg_queue_wait_seconds'] ?? 0), 2),
    ];
  }

}
