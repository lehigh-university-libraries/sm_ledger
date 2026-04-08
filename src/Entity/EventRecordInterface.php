<?php

namespace Drupal\sm_ledger\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Interface for ledger tracking records.
 */
interface EventRecordInterface extends ContentEntityInterface, EntityChangedInterface {}
