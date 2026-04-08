<?php

namespace Drupal\sm_ledger\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Exports ledger rows that match the current retention policy.
 */
class LedgerArchiveService {

  /**
   * Event record storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $storage;

  /**
   * Constructs the archive service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private LedgerRetentionService $retention,
  ) {
    $this->storage = $entityTypeManager->getStorage('event_record');
  }

  /**
   * Exports configured retention candidates to newline-delimited JSON.
   *
   * @return array<string, int>
   *   Exported row counts keyed by status.
   */
  public function archiveConfigured(string $path, int $limit = 500, bool $delete = FALSE): array {
    $file = new \SplFileObject($path, 'a');
    $exported = [];

    foreach ($this->retention->getConfiguredPolicies() as $status => $days) {
      if ($days <= 0) {
        continue;
      }

      $records = $this->retention->loadCandidates($status, $days, $limit);
      foreach ($records as $record) {
        $file->fwrite(json_encode($this->normalizeRecord($record), JSON_UNESCAPED_SLASHES) . PHP_EOL);
      }

      if ($delete && $records !== []) {
        $this->storage->delete($records);
      }

      $exported[$status] = count($records);
    }

    return $exported;
  }

  /**
   * Normalizes one ledger row for export.
   *
   * @return array<string, mixed>
   *   Normalized export row.
   */
  private function normalizeRecord(ContentEntityInterface $record): array {
    $fields = [];
    foreach ($record->getFields() as $fieldName => $items) {
      $fields[$fieldName] = $items->getValue();
    }

    return [
      'archived_at' => gmdate(DATE_ATOM),
      'entity_type' => $record->getEntityTypeId(),
      'id' => (int) $record->id(),
      'uuid' => $record->uuid(),
      'fields' => $fields,
    ];
  }

}
