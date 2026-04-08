<?php

namespace Drupal\sm_ledger\Service;

use Drupal\sm_workers\Service\TransportQueueDepthService;

/**
 * Builds a point-in-time capacity snapshot across workers and ledger rows.
 */
final class LedgerCapacityReportService {

  /**
   * Constructs the capacity report service.
   */
  public function __construct(
    private TransportQueueDepthService $queueDepths,
    private LedgerAnalyticsService $analytics,
  ) {}

  /**
   * Builds a capacity report.
   *
   * @param int $windowMinutes
   *   Observation window used for throughput and latency summaries.
   * @param float $targetRps
   *   Optional target throughput in records per second.
   *
   * @return array<string, mixed>
   *   Capacity report data.
   */
  public function buildReport(int $windowMinutes = 15, float $targetRps = 0.0): array {
    $windowMinutes = max(1, $windowMinutes);
    $cutoff = time() - ($windowMinutes * 60);

    $queueDepths = $this->queueDepths->queueDepths();
    $statusCounts = $this->analytics->statusCounts();
    $completion = $this->analytics->completionSummary($cutoff, $windowMinutes);
    $processing = $this->analytics->processingLatencySummary($cutoff);

    return [
      'window_minutes' => $windowMinutes,
      'target_rps' => $targetRps,
      'queue_depths' => $queueDepths,
      'status_counts' => $statusCounts,
      'completion' => $completion,
      'processing' => $processing,
      'recommendations' => $this->recommendations(
        $queueDepths,
        $statusCounts,
        $completion,
        $processing,
        $targetRps,
      ),
    ];
  }

  /**
   * Returns generic operator recommendations from the snapshot.
   *
   * @param array<string, int> $queueDepths
   *   Queue depth keyed by transport.
   * @param array<string, int> $statusCounts
   *   Ledger counts keyed by status.
   * @param array<string, float|int> $completion
   *   Completion throughput summary.
   * @param array<string, float|int> $processing
   *   Timing summary.
   * @param float $targetRps
   *   Optional throughput target in records per second.
   *
   * @return string[]
   *   Recommendation lines.
   */
  private function recommendations(
    array $queueDepths,
    array $statusCounts,
    array $completion,
    array $processing,
    float $targetRps,
  ): array {
    $messages = [];
    $totalQueueDepth = array_sum($queueDepths);
    $inFlight = (int) ($statusCounts['queued'] ?? 0) + (int) ($statusCounts['retry_due'] ?? 0);

    if ($totalQueueDepth === 0 && $inFlight === 0) {
      $messages[] = 'Queue depth is currently low; the existing worker footprint is likely sufficient at this load.';
    }
    if ($totalQueueDepth >= 1000 || $inFlight >= 5000) {
      $messages[] = 'Backlog is elevated; add transport-specific workers first and consider moving worker processes out of the web container if this persists.';
    }
    if ((float) ($processing['avg_queue_wait_seconds'] ?? 0) >= 30) {
      $messages[] = 'Average queue wait is elevated; worker concurrency is likely the first tuning point.';
    }
    if ($targetRps > 0 && (float) ($completion['completed_rps'] ?? 0) < $targetRps) {
      $messages[] = sprintf(
        'Measured completion throughput is below the target %.3f rps; benchmark with more workers before changing architecture.',
        $targetRps,
      );
    }
    if ($targetRps > 0 && (float) ($completion['completed_rps'] ?? 0) >= $targetRps) {
      $messages[] = sprintf(
        'Measured completion throughput meets or exceeds the target %.3f rps for the current observation window.',
        $targetRps,
      );
    }
    if ($messages === []) {
      $messages[] = 'No immediate scaling signal was detected from this snapshot. Validate with sustained ingest testing before increasing complexity.';
    }

    return $messages;
  }

}
