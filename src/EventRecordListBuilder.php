<?php

namespace Drupal\sm_ledger;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists ledger record entities.
 */
class EventRecordListBuilder extends EntityListBuilder {

  /**
   * Formats a Unix timestamp as ISO 8601.
   */
  private function formatIsoDate(int $timestamp): string {
    return gmdate(DATE_ATOM, $timestamp);
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['status'] = $this->t('Status');
    $header['event_kind'] = $this->t('Kind');
    $header['target_system'] = $this->t('Target');
    $header['source'] = $this->t('Source');
    $header['attempt_count'] = $this->t('Attempts');
    $header['retry_count'] = $this->t('Retries');
    $header['needs_processing'] = $this->t('Needs processing');
    $header['next_attempt_at'] = $this->t('Next attempt');
    $header['requires_manual_intervention'] = $this->t('Manual');
    $header['emitted_at'] = $this->t('Emitted');
    $header['completed_at'] = $this->t('Completed');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['id'] = $entity->id();
    $row['status'] = $entity->get('status')->value;
    $row['event_kind'] = $entity->get('event_kind')->value;
    $row['target_system'] = $entity->get('target_system')->value;
    $row['source'] = sprintf('%s:%s',
      $entity->get('source_entity_type')->value,
      $entity->get('source_entity_id')->value
    );
    $row['attempt_count'] = $entity->get('attempt_count')->value;
    $row['retry_count'] = $entity->get('retry_count')->value;
    $row['needs_processing'] = $entity->get('needs_processing')->value ? 'Yes' : 'No';
    $nextAttemptAt = (int) $entity->get('next_attempt_at')->value;
    $row['next_attempt_at'] = $nextAttemptAt > 0 ? $this->formatIsoDate($nextAttemptAt) : '-';
    $row['requires_manual_intervention'] = $entity->get('requires_manual_intervention')->value ? 'Yes' : 'No';
    $row['emitted_at'] = $this->formatIsoDate((int) $entity->get('emitted_at')->value);
    $completedAt = (int) $entity->get('completed_at')->value;
    $row['completed_at'] = $completedAt > 0 ? $this->formatIsoDate($completedAt) : '-';
    return $row + parent::buildRow($entity);
  }

}
