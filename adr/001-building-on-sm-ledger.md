# ADR 001: Design `sm_ledger` so other modules can build on top of it

## Status

Accepted

## Context

If `sm_ledger` is going to be a standalone codebase, it needs to provide more
than a private storage table. Other modules need a stable way to attach their
own domain semantics to Messenger-backed work without forking the lifecycle
machinery.

The value of the ledger is not just that rows exist. The value is that modules
can build consistent operator features on top of the same projection:

- report pages
- per-entity event history
- retry and manual intervention workflows
- retention and archival commands
- metrics, tracing, and correlation hooks

## Decision

`sm_ledger` will stay generic and expose stable APIs and extension surfaces for
other modules to build on.

In practice that means:

- event records stay domain-neutral
- lifecycle updates happen through ledger services rather than ad hoc writes
- SQL-backed producer code can use shared `record + dispatch` orchestration
- operator actions such as requeue and run-now are generic services with tagged handlers
- aggregate analytics and capacity snapshots live in the shared ledger layer
- retention and archive behavior are part of the reusable module
- consumer modules may derive business-specific correlation and dedupe keys, but
  the shared dedupe-window behavior lives in the shared ledger model
- downstream modules add their own message types, correlation keys, UI, and
  business workflows on top of the shared ledger model

## Reasoning

### Reuse only matters if the boundary is stable

If every consumer module has to reach around the services and write raw entity
state directly, `sm_ledger` is not really a reusable module. The service
boundary is part of the product.

### Shared operator semantics reduce duplicated infrastructure

Modules that adopt `sm_ledger` get a ready-made lifecycle vocabulary and
operator model instead of inventing new tables, retention rules, and report
surfaces every time they add Messenger-backed work.

### Retention and archival belong in the shared layer

Hot-table retention and archive export are part of running a ledger well. They
should not be reimplemented independently by each consumer module.

## Consequences

- the module should be documented as a reusable foundation, not a private
  Islandora detail
- consumer modules should prefer ledger services and extension points over raw
  entity manipulation
- Messenger middleware such as `DeduplicateStamp` may still be useful for other
  applications, but it is not the ledger's correctness boundary
- retention and archive workflows are part of the value `sm_ledger` provides
