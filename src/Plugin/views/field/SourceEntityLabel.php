<?php

namespace Drupal\sm_ledger\Plugin\views\field;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the ledger record's source entity label.
 *
 * @ViewsField("sm_ledger_source_entity_label")
 */
final class SourceEntityLabel extends FieldPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Cached storages keyed by entity type.
   *
   * @var array<string, \Drupal\Core\Entity\EntityStorageInterface>
   */
  private array $storages = [];

  /**
   * Constructs the field plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Uses fields already present on the base row; no extra query work needed.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): string {
    $entityType = (string) ($values->sm_ledger_event_record_source_entity_type ?? '');
    $entityId = (int) ($values->sm_ledger_event_record_source_entity_id ?? 0);

    if ($entityType === '' || $entityId <= 0) {
      return '';
    }

    $storage = $this->getStorage($entityType);
    if ($storage === NULL) {
      return sprintf('%s:%d', $entityType, $entityId);
    }

    $entity = $storage->load($entityId);
    if ($entity === NULL) {
      return sprintf('%s:%d', $entityType, $entityId);
    }

    $label = trim((string) $entity->label());
    return $label !== '' ? $label : sprintf('%s:%d', $entityType, $entityId);
  }

  /**
   * Returns storage for an entity type when it exists.
   */
  private function getStorage(string $entityType): ?EntityStorageInterface {
    if (array_key_exists($entityType, $this->storages)) {
      return $this->storages[$entityType];
    }

    if (!$this->entityTypeManager->hasDefinition($entityType)) {
      $this->storages[$entityType] = NULL;
      return NULL;
    }

    $this->storages[$entityType] = $this->entityTypeManager->getStorage($entityType);
    return $this->storages[$entityType];
  }

}
