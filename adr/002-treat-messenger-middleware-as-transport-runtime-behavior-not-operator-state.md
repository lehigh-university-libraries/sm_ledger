# ADR 002: Treat Messenger middleware as transport/runtime behavior, not operator state

## Status

Accepted

## Context

`sm_ledger` exists because Symfony Messenger transports and worker logs do not
provide a durable, application-facing record of work on their own.

Symfony Messenger middleware is still a useful extension point. Middleware can
stamp envelopes, alter transport/runtime behavior, and participate in dispatch
and receive flows. Recent support for Symfony's `DeduplicateMiddleware` in
`drupal/sm` makes that especially relevant for deduplication discussions.

We need to decide where three related concerns belong:

- business-level deduplication
- lifecycle projection for queued, running, retried, failed, and completed work
- operator-facing state used for reporting, replay, and manual intervention

These concerns overlap, but they are not the same thing.

The question is not whether middleware is useful. The question is whether
middleware should be the primary correctness boundary and source of truth for
durable workflow state.

## Decision

`sm_ledger` will treat Messenger middleware as a transport/runtime extension
point, not as the primary mechanism for operator state.

In practice that means:

- Messenger remains responsible for message delivery, routing, retries, and
  worker execution
- middleware may be used for transport-level or envelope-level behavior such as
  tracing, stamps, or temporary runtime deduplication
- business-level deduplication is enforced through ledger services and durable
  dedupe keys, not by Messenger lock middleware alone
- lifecycle projection is driven by worker outcomes and persisted into ledger
  records
- operator-facing state such as retry history, manual intervention, and replay
  workflows lives in `sm_ledger`
- handlers remain idempotent because delivery is still at-least-once

## Reasoning

### Middleware is useful, but it is the wrong authority for operator state

Middleware executes inside Messenger's dispatch and receive pipeline. That makes
it a good fit for concerns that are naturally about message flow:

- envelope stamping
- runtime tracing
- transport behavior
- temporary technical deduplication

It is a poor fit for durable operator semantics because middleware state is
typically:

- transient
- lock-oriented
- coupled to bus execution
- not naturally queryable as a durable workflow record

Those are weak properties for the system of record that operators depend on.

### Business deduplication is broader than lock-based duplicate suppression

Framework-level deduplication such as Symfony's `DeduplicateMiddleware` is a
useful runtime optimization. It can prevent the same stamped message from being
dispatched or processed concurrently through a lock-backed mechanism.

That is not sufficient for `sm_ledger`'s contract.

`sm_ledger` needs deduplication that is:

- expressed by a business key
- durable and queryable from Drupal
- configurable by suppression window
- visible to operator workflows

That requires a persistent ledger model. A temporary lock alone cannot answer
why work was suppressed, whether it should be replayed, or what happened across
the full lifecycle.

### Lifecycle state should be projected from worker outcomes

Operator lifecycle state should reflect what actually happened during worker
execution.

For Messenger-backed work, the durable boundary is not "a middleware ran." The
durable boundary is that a worker handled, retried, or terminally failed a
message.

That is why `sm_ledger` projects lifecycle state from worker events into
persistent records instead of treating middleware as the lifecycle authority.

### Handler idempotency remains part of the correctness model

Neither middleware deduplication nor ledger deduplication removes the need for
idempotent handlers.

Messenger delivery remains at-least-once. Duplicate delivery can still happen
through retries, crashes, visibility windows, or operator-triggered replay.
Handlers must therefore remain safe to run more than once for the same logical
work item.

## Alternatives Considered

### Use Messenger middleware as the primary dedupe and state mechanism

This approach keeps more logic inside Messenger and can use built-in framework
features such as `DeduplicateMiddleware`.

We reject it as the primary model for `sm_ledger` because:

- middleware locks are temporary and not the durable source of truth
- operator workflows need persistent, queryable state
- business deduplication windows are domain semantics, not only transport
  mechanics
- lifecycle reporting needs actual execution outcomes, not only pipeline hooks

Middleware-led deduplication may still be useful as a narrow runtime safeguard,
but it is not the ledger's correctness boundary.

### Use a formal workflow orchestration engine

The strongest architectural alternative is not richer middleware. It is a
dedicated workflow engine such as Temporal or Camunda.

That alternative has real advantages:

- first-class durable workflow state
- explicit orchestration for long-running processes
- better visibility into end-to-end flow
- stronger support for retries, compensation, and human intervention

This is the most credible criticism of a ledger-plus-subscribers approach. A
workflow engine can provide a clearer model for complex lifecycles than ad hoc
state projected across handlers and subscribers.

We are not adopting that model here because `sm_ledger` is intended to be a
reusable Drupal module with Drupal-native deployment boundaries. Requiring a
separate workflow runtime would materially raise adoption and operational cost.

`sm_ledger` therefore accepts the burden of maintaining a durable projection in
Drupal instead of introducing an external orchestration dependency.

### Accept ledger and outbox complexity as the price of durable operator state

The ledger pattern is not free.

It increases:

- database write load
- contention risk around hot rows and dispatch coordination
- cleanup and retention requirements
- application code that must consistently use ledger services

We accept those costs because they buy durable, queryable operator state inside
the same Drupal runtime that owns the business workflows.

## Industry Context

This decision matches the way mature event-driven systems usually separate
concerns:

- brokers and middleware own delivery/runtime behavior
- consumers own idempotency
- application storage owns business state
- operator workflows are built on durable application records, not broker
  internals

Highly respected sources make similar distinctions:

- Confluent's material on Kafka delivery semantics and exactly-once behavior
  emphasizes that stronger guarantees require cooperation between the messaging
  system and the application, not broker magic alone<sup>[1](#reference-1)</sup>
- AWS SQS guidance tells consumers to expect duplicate delivery and design for
  idempotency<sup>[2](#reference-2)</sup>
- Chris Richardson's idempotent consumer pattern treats duplicate handling as a
  durable application concern<sup>[3](#reference-3)</sup>
- Martin Fowler and Bernd Ruecker both warn that event-driven systems can lose
  end-to-end visibility when business workflow state is too implicit<sup>[4](#reference-4)</sup>

These sources support the same architectural split:

- transport/runtime semantics belong in the messaging layer
- durable business semantics belong in application-owned state

## Consequences

- Messenger middleware remains available for runtime concerns, but it is not
  the source of truth for operator workflows
- `sm_ledger` owns durable business deduplication, lifecycle projection, and
  operator-facing state
- consumer modules should dispatch through ledger services when they need
  durable workflow tracking
- handlers must remain idempotent
- the project accepts the database and maintenance costs of a ledger model
  instead of requiring an external orchestration engine

## References

1. <a id="reference-1"></a>Confluent, "Kafka Message Delivery Guarantees": https://docs.confluent.io/kafka/design/delivery-semantics.html; Confluent, "Simplified, Robust Exactly-Once Semantics in Kafka 2.5": https://www.confluent.io/blog/exactly-once-semantics-are-possible-heres-how-apache-kafka-does-it/
2. <a id="reference-2"></a>AWS, "Amazon SQS at-least-once delivery": https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/standard-queues-at-least-once-delivery.html; AWS, "Amazon SQS queue types": https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-queue-types.html
3. <a id="reference-3"></a>Chris Richardson, "Pattern: Idempotent Consumer": https://microservices.io/patterns/communication-style/idempotent-consumer.html
4. <a id="reference-4"></a>Martin Fowler, "What do you mean by Event-Driven?": https://martinfowler.com/articles/201701-event-driven.html; Bernd Ruecker, "How to tame event-driven microservices": https://berndruecker.io/how-to-tame-event-driven-microservices/
