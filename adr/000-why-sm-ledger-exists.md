# ADR 000: Create `sm_ledger` as a durable operator projection for Messenger work

## Status

Accepted

## Context

Symfony Messenger transports and worker logs do not provide a durable,
application-facing record of work on their own.

Drupal applications need an operator surface that answers:

- what work was queued
- which object and user it belongs to
- whether it is still processable
- when it started and finished
- how many retries occurred
- whether it now needs manual intervention

That information should still be available after the queue row is gone and
after worker logs have rotated away.

## Decision

`sm_ledger` exists to provide that durable operator projection as a reusable
Drupal module.

It is not a transport, not a worker runtime, and not an Islandora-specific
module. It models Messenger-backed work as durable event records that other
modules can query, display, prune, archive, and build operator workflows on
top of.

## Consequences

- Messenger remains responsible for delivery and retry execution
- `sm_ledger` remains responsible for durable lifecycle state and operator
  workflows
- when a consumer uses `dedupe_key`, that dedupe is enforced by ledger service
  logic for the configured suppression window rather than by temporary
  Messenger lock middleware alone
- other codebases can adopt the ledger without adopting Islandora-specific
  orchestration
