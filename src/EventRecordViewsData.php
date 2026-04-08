<?php

namespace Drupal\sm_ledger;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for ledger records.
 */
final class EventRecordViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();

    $data['sm_ledger_event_record']['source_entity_label'] = [
      'title' => $this->t('Source'),
      'help' => $this->t('Displays the source entity label for the ledger record.'),
      'field' => [
        'id' => 'sm_ledger_source_entity_label',
      ],
    ];

    return $data;
  }

}
