<?php

namespace Drupal\sm_ledger\Service;

/**
 * Projection updater for the durable ledger read model.
 *
 * This wraps the existing event record lifecycle API in terminology that makes
 * the architectural boundary clearer: Messenger owns delivery/runtime, while
 * the ledger is the materialized operational projection.
 */
class LedgerProjectionService extends EventRecordService {}
