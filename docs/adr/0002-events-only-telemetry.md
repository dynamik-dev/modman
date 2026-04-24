# ADR 0002: Events-only telemetry

## Context

A moderation pipeline is exactly the kind of system operators want to monitor: grader latency, reject rates, queue depth, how often the LLM errors out, how long reports sit in `needs_human`. There are three places the package could inject this: (1) call `Log::info(...)` and `Metrics::increment(...)` directly inside the orchestrator and transitions, (2) ship an opinionated observability bundle (Prometheus exporter, Sentry breadcrumbs, structured log channel), or (3) dispatch domain events and let the consumer wire whatever observability stack they already use.

## Decision

The package only dispatches events. `ReportCreated`, `ReportTransitioned`, `GraderRan`, `ReportAwaitingHuman`, `ReportResolved`, and `ReportReopened` cover every state-changing moment. The package never calls `Log::`, `Metrics::`, or similar. Consumers add their own listeners — a Sentry listener, a StatsD listener, a Slack listener — to integrate with whatever they already run.

## Consequences

Upside: zero opinionated coupling to log channels, metric backends, or tracing libraries. A Laravel Forge shop with CloudWatch, a New Relic shop, and a Prometheus shop can all adopt modman with a single package version. The event names and payloads become the public telemetry contract; we treat them as breaking changes. Downside: a new consumer gets no default dashboards and must write a handful of listeners to see anything beyond the `moderation_transitions` and `moderation_decisions` tables. We accept that because most teams already have an observability stack, and it's easier to wire six listeners than to rip out opinionated ones we guessed wrong about.
