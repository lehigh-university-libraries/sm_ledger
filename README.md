# SM Ledger

`sm_ledger` is the domain ledger layer for Symfony Messenger work in Drupal.

It is not a transport and it is not a queue runner.

Its job is to persist the application-facing record of work:

- what happened
- which source entity it belongs to
- who initiated it
- current status
- timing
- retries
- failures
- manual intervention state

Messenger still owns delivery and worker execution. `sm_ledger` owns the durable
record operators need to inspect and manage work from inside Drupal.

## What The Ledger Is For

Use the ledger to answer questions like:

- Did this job queue?
- Is it still pending?
- When did processing start?
- How many retries happened?
- Did it finish, fail, or get abandoned?
- Which source object and user was this tied to?

Use logs and traces to answer deeper diagnostic questions like:

- Why did the worker fail?
- Which downstream service timed out?
- Was the database unavailable?
- Which transport/runtime exception actually occurred?

## Operator Model

Use the ledger first:

- inspect record status
- inspect `last_error`
- inspect retry and timing fields
- identify the source entity and correlation key
- decide whether requeue or manual intervention is appropriate

Pivot to logs when:

- `last_error` is truncated or generic
- the failure came from a downstream service
- you need stack traces or transport/runtime detail
- the worker appears unhealthy even though queued records exist

## Current Status Model

Typical lifecycle:

```text
queued -> in_progress -> completed
queued -> in_progress -> retry_due
queued -> in_progress -> failed
queued -> in_progress -> abandoned
```

The lifecycle APIs now guard the main status transitions. The shared services
expect the normal worker path rather than arbitrary caller-driven state jumps.

`failed` means the work did not complete successfully.

`requires_manual_intervention = true` means automatic retries are exhausted and
an operator should inspect the ledger record and worker logs before requeueing.

`attempt_count` includes stale-claim recovery. If a worker dies after claiming a
row and another worker later reclaims that stale in-progress row, the second
claim increments `attempt_count` again.

## What Not To Put In The Ledger

Do not treat `sm_ledger` as:

- a replacement transport
- a second queue implementation
- a dump of every runtime detail from workers

High-cardinality diagnostics belong in logs and tracing systems. The ledger
should stay focused on durable workflow state and operator-visible outcomes.

## Report Page

Runtime settings are managed from:

`/admin/config/system/sm-ledger/settings`

`/admin/reports/sm-ledger`

Use the report page to review:

- pending depth
- average processing time
- recent failures

The report helps identify whether the next step is:

- add workers
- inspect a failing downstream integration
- investigate worker logs
- requeue or manually resolve stuck work

## Retention And Archival

Use retention to keep the hot ledger table focused on active operational work.

Commands:

- `drush sm-ledger:prune`
- `drush sm-ledger:prune --apply`
- `drush sm-ledger:archive`
- `drush sm-ledger:archive --apply-prune`

`sm-ledger:archive` exports retention-matching rows to newline-delimited JSON.
Use that before pruning when long-term history still matters but does not need
to stay in Drupal’s hot operator surface.

## Architecture Decisions

See [`adr/`](adr/) for the module-owned decisions behind `sm_ledger`:

## Building On Top Of The Ledger

Reach for `sm_ledger` when your module has Messenger-backed work and you want a
durable operator model without inventing your own lifecycle table.

Typical value:

- a stable lifecycle vocabulary
- durable per-record state after queue rows are gone
- operator workflows around retry, failure, and manual intervention
- shared dispatch coordination for SQL-backed producer flows
- bounded deadlock retry for SQL-backed `record + dispatch`
- shared aggregate analytics and capacity reporting
- retention and archive commands for keeping the hot table manageable

Typical integration pattern:

- your module defines the domain message types and handlers
- your module writes correlation keys, dedupe keys, and source-object metadata
- `sm_ledger` owns the shared event record model and lifecycle services

Use the services as the boundary. Avoid treating the ledger entity as a raw
write target from unrelated business code.

## Dedupe Window

`dedupe_key` is a time-bounded suppression key, not a permanent uniqueness
constraint.

By default, duplicate records with the same `dedupe_key` are suppressed for
24 hours. After that window expires, the same business event may be recorded
again.

The dedupe window is configured in `sm_ledger.settings:dedupe.ttl_seconds`.

## Producer Contracts

`recordQueuedEvent()` persists the ledger row only. If your module wants the
shared SQL-backed transaction wrapper around ledger-write plus Messenger
dispatch, use `sm_ledger.dispatch`.

Async transport messages should carry the ledger `correlation_key` as their
stable reference. Treat the numeric row ID as optional compatibility data, not
as the primary cross-process identifier.

`correlation_key` should be unique per recorded row. Use `dedupe_key` for the
time-bounded business suppression key and `correlation_key` for immutable
message-to-row binding across retries and redeliveries.

With SQL-backed Messenger transports, producer code can keep the ledger row and
queue row in the same database transaction. If a consumer module chooses a
non-SQL transport such as AMQP or Redis, it does not get the same atomicity
guarantee without adding a dedicated outbox relay.

If your module wants shared operator actions from the Drupal UI, register tagged
handlers with `sm_ledger.requeue_handler` and `sm_ledger.run_now_handler` and
let `sm_ledger.operator` coordinate the generic replay or run-now workflow.

## Recovery Tooling

`sm_ledger` includes recovery-oriented services such as stale-claim requeue and
row claiming for explicit replay/drain workflows. Those capabilities are
intended for operator tooling and one-shot recovery flows, not as a replacement
for Messenger transport consumption during normal application runtime.

Stale-claim recovery is timeout-based. Native replay paths should refresh
`last_started_at` periodically while work is still running so long executions
do not get reclaimed early. The shared heartbeat interval is configured in
`sm_ledger.settings:recovery.heartbeat_interval_seconds` and should remain well
below `recovery.stale_claim_threshold_seconds`.

For SQL-backed Messenger transports, keep the transport visibility timeout
above the real worker envelope: execution timeout, downstream write-back time,
bounded retry delays, and any deliberate intake pause while a breaker is open.
The repository defaults set `redeliver_timeout: 14400` for that reason.

## Shared Services

`sm_ledger` now owns reusable services for:

- ledger lifecycle projection
- SQL-backed dedupe-safe `record + dispatch` coordination via `sm_ledger.dispatch`
- operator actions over persisted records via `sm_ledger.operator`
- aggregate analytics and capacity snapshots via `sm_ledger.analytics` and `sm_ledger.capacity_report`
