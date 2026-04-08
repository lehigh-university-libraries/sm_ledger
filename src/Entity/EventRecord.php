<?php

namespace Drupal\sm_ledger\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the ledger record entity.
 *
 * @ContentEntityType(
 *   id = "event_record",
 *   label = @Translation("Ledger Record"),
 *   label_collection = @Translation("Ledger Records"),
 *   handlers = {
 *     "list_builder" = "Drupal\sm_ledger\EventRecordListBuilder",
 *     "access" = "Drupal\sm_ledger\EventRecordAccessControlHandler",
 *     "storage_schema" = "Drupal\sm_ledger\EventRecordStorageSchema",
 *     "views_data" = "Drupal\sm_ledger\EventRecordViewsData",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "sm_ledger_event_record",
 *   admin_permission = "administer sm ledger",
 *   indexes = {
 *     "status_due" = {"needs_processing", "status", "next_attempt_at", "id"},
 *     "queue_due" = {"event_kind", "queue_name", "needs_processing", "id"},
 *     "source_lookup" = {
 *       "source_entity_type",
 *       "source_entity_id",
 *       "trigger_event_type",
 *       "needs_processing",
 *       "id"
 *     },
 *     "correlation_key" = {"correlation_key"},
 *     "dedupe_key" = {"dedupe_key"},
 *     "emitted_at" = {"emitted_at"},
 *     "completed_at" = {"completed_at"}
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "correlation_key"
 *   },
 *   links = {
 *     "canonical" = "/admin/config/system/sm-ledger/{event_record}",
 *     "collection" = "/admin/config/system/sm-ledger"
 *   }
 * )
 */
class EventRecord extends ContentEntityBase implements EventRecordInterface {

  use EntityChangedTrait;

  public const STATUS_QUEUED = 'queued';
  public const STATUS_IN_PROGRESS = 'in_progress';
  public const STATUS_COMPLETED = 'completed';
  public const STATUS_FAILED = 'failed';
  public const STATUS_RETRY_DUE = 'retry_due';
  public const STATUS_ABANDONED = 'abandoned';

  public const KIND_DERIVATIVE = 'derivative';
  public const KIND_INDEXING = 'indexing';
  public const KIND_CUSTOM = 'custom';

  public const TRANSPORT_NATIVE = 'native';

  /**
   * Returns allowed values for the status field.
   *
   * @return array<string, string>
   *   Allowed values keyed by stored value.
   */
  public static function getStatusOptions(): array {
    return [
      self::STATUS_QUEUED => 'Queued',
      self::STATUS_IN_PROGRESS => 'In progress',
      self::STATUS_COMPLETED => 'Completed',
      self::STATUS_FAILED => 'Failed',
      self::STATUS_RETRY_DUE => 'Retry due',
      self::STATUS_ABANDONED => 'Abandoned',
    ];
  }

  /**
   * Returns allowed values for the event kind field.
   *
   * @return array<string, string>
   *   Allowed values keyed by stored value.
   */
  public static function getEventKindOptions(): array {
    return [
      self::KIND_DERIVATIVE => 'Derivative',
      self::KIND_INDEXING => 'Indexing',
      self::KIND_CUSTOM => 'Custom',
    ];
  }

  /**
   * Returns allowed values for the transport mode field.
   *
   * @return array<string, string>
   *   Allowed values keyed by stored value.
   */
  public static function getTransportModeOptions(): array {
    return [
      self::TRANSPORT_NATIVE => 'Native',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setReadOnly(TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', static::getStatusOptions())
      ->setDefaultValue(self::STATUS_QUEUED);

    $fields['event_kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Event kind'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', static::getEventKindOptions())
      ->setDefaultValue(self::KIND_CUSTOM);

    $fields['target_system'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target system'))
      ->setSetting('max_length', 64)
      ->setDefaultValue('custom');

    $fields['needs_processing'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Needs processing'))
      ->setDefaultValue(TRUE);

    $fields['action_plugin_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Action ID'))
      ->setSetting('max_length', 128)
      ->setDefaultValue('');

    $fields['source_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source entity type'))
      ->setSetting('max_length', 64)
      ->setDefaultValue('');

    $fields['source_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Source entity ID'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['source_entity_uuid'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source entity UUID'))
      ->setSetting('max_length', 128)
      ->setDefaultValue('');

    $fields['initiating_user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Initiating user'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDefaultValue(NULL);

    $fields['trigger_event_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Trigger event type'))
      ->setSetting('max_length', 128)
      ->setDefaultValue('');

    $fields['correlation_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Correlation key'))
      ->setSetting('max_length', 255)
      ->setDefaultValue('');

    $fields['dedupe_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Deduplication key'))
      ->setSetting('max_length', 255)
      ->setDefaultValue('');

    $fields['transport_mode'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Transport mode'))
      ->setSetting('allowed_values', static::getTransportModeOptions())
      ->setDefaultValue(self::TRANSPORT_NATIVE);

    $fields['queue_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Queue name'))
      ->setSetting('max_length', 128)
      ->setDefaultValue('');

    $fields['payload_json'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Payload JSON'))
      ->setDefaultValue('');

    $fields['transport_metadata'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Transport metadata'))
      ->setDefaultValue('');

    $fields['last_error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Last error'))
      ->setDefaultValue('');

    $fields['attempt_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Attempt count'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['retry_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Retry count'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['manual_intervention_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Manual intervention count'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['requires_manual_intervention'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Requires manual intervention'))
      ->setDefaultValue(FALSE);

    $fields['emitted_at'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Emitted at'));

    $fields['first_started_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('First started at'))
      ->setDefaultValue(0);

    $fields['last_started_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last started at'))
      ->setDefaultValue(0);

    $fields['next_attempt_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Next attempt at'))
      ->setDefaultValue(0);

    $fields['completed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Completed at'))
      ->setDefaultValue(0);

    $fields['last_manual_intervention_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last manual intervention at'))
      ->setDefaultValue(0);

    $fields['manual_intervention_notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Manual intervention notes'))
      ->setDefaultValue('');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    foreach ($fields as $field) {
      $field->setDisplayConfigurable('view', TRUE);

      switch ($field->getType()) {
        case 'boolean':
          $field->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'boolean',
            'weight' => 0,
            'settings' => [
              'format' => 'default',
              'format_custom_false' => '',
              'format_custom_true' => '',
            ],
          ]);
          break;

        case 'created':
        case 'changed':
        case 'timestamp':
          $field->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'timestamp',
            'weight' => 0,
            'settings' => [
              'date_format' => 'medium',
              'custom_date_format' => '',
              'timezone' => '',
              'tooltip' => FALSE,
            ],
          ]);
          break;

        case 'integer':
          $field->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'number_integer',
            'weight' => 0,
            'settings' => [
              'thousand_separator' => '',
              'prefix_suffix' => TRUE,
            ],
          ]);
          break;

        case 'list_string':
          $field->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'list_default',
            'weight' => 0,
            'settings' => [],
          ]);
          break;

        case 'string':
        case 'uuid':
          $field->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'string',
            'weight' => 0,
            'settings' => [
              'link_to_entity' => FALSE,
            ],
          ]);
          break;
      }
    }

    return $fields;
  }

}
