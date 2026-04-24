# ADR 0001: Use spatie/laravel-model-states for the Report FSM

## Context

Report progresses through six states (`pending`, `screening`, `needs_llm`, `needs_human`, `resolved_approved`, `resolved_rejected`) with a small set of allowed transitions. Two credible approaches: a homegrown enum column guarded by assertions scattered through services, or a dedicated state-machine library. The homegrown path looks lighter on day one, but moderation is exactly the domain where illegal transitions (e.g. `pending -> resolved_approved` skipping screening, or resolving an already-resolved report) must be impossible, not merely unlikely. We also want every transition to carry audit metadata — actor, reason, timestamp — and to be emitted as an event so consumers can build their own audit trails.

## Decision

Use `spatie/laravel-model-states` v2.7+. Each state is a class (`Pending`, `Screening`, etc.). `ReportState::config()` declares allowed transitions once; spatie rejects illegal ones at the database-model boundary. Every transition runs through a `LoggedTransition` base class that inserts a row into `moderation_transitions` (with actor and reason) and dispatches `ReportTransitioned`. Explicit transition classes (`ToScreening`, `ToNeedsLlm`, `ToResolvedApproved`, `Reopen`, etc.) are the only way state changes happen — the orchestrator, the model's `resolveApprove/resolveReject/reopen` helpers, and the job failure handler all route through them.

## Consequences

We get typed states, compile-time checkable transition targets, a free `allowed transitions` graph exposed by spatie, and a single audit log that records who moved what when. The tradeoff is a dependency and a small amount of ceremony per new state (a class plus an entry in `ReportState::config()`). This is worth it because moderation is the kind of domain where a silently invalid transition ships to production and nobody notices for weeks. Consumers who want to add states (e.g. `shadow_banned`) extend the same config; spatie validates the graph at runtime on boot.
