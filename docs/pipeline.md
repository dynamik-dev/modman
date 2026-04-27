# Pipeline

modman models moderation as a finite-state machine over `Dynamik\Modman\Models\Report`. Each report has a state, managed by [`spatie/laravel-model-states`](https://spatie.be/docs/laravel-model-states). The orchestrator walks the configured grader pipeline until the policy auto-resolves or routes to a human.

## States

| State class | String value | Terminal |
| --- | --- | --- |
| `Pending` | `pending` | no |
| `Screening` | `screening` | no |
| `NeedsLlm` | `needs_llm` | no |
| `NeedsHuman` | `needs_human` | no |
| `ResolvedApproved` | `resolved_approved` | yes |
| `ResolvedRejected` | `resolved_rejected` | yes |

## Allowed transitions

```
pending           -> screening
screening         -> resolved_approved | resolved_rejected | needs_llm
needs_llm         -> resolved_approved | resolved_rejected | needs_human
needs_human       -> resolved_approved | resolved_rejected
resolved_approved -> needs_human        (reopen)
resolved_rejected -> needs_human        (reopen)
```

Terminal states are only escapable via `Report::reopen()`, which lands back in `needs_human` for a human to re-decide.

## Flow

1. A host model calls `$post->report($reporter, $reason)`. Report is inserted with state `pending`; `ReportCreated` is dispatched; `RunModerationPipeline` is queued.
2. The job invokes `Orchestrator::runNext($report)`:
   - If state is `pending`, transition to `screening`.
   - Look up the next grader key that has not produced a decision yet from `config('modman.pipeline')`.
   - Call `$grader->supports($content)`. If false, write a `skipped` decision and queue another iteration.
   - Call `$grader->grade($content, $report)`. Write a `Decision`, dispatch `GraderRan`.
   - Ask the policy: `decide($report, $decision) -> PolicyAction`.
   - Apply the action: `Approve` / `Reject` transition to a terminal state and fire `ReportResolved`. `EscalateTo('llm')` transitions to `needs_llm` and queues another run. `RouteToHuman` transitions to `needs_human` and fires `ReportAwaitingHuman`.
3. Human moderators call `$report->resolveApprove($moderator)` or `$report->resolveReject($moderator)` to land on a terminal state. `$report->reopen($actor)` reopens from terminal back to `needs_human`.

Every state change goes through a `LoggedTransition` that inserts a row into `moderation_transitions` and fires `ReportTransitioned`.

## Default pipeline

From `config/modman.php`:

```php
'pipeline' => [
    'denylist' => Dynamik\Modman\Graders\DenylistGrader::class,
    'llm' => Dynamik\Modman\Graders\LlmGrader::class,
],
```

The key is the stable grader identifier; the value is the fully qualified class. Order matters — graders run top to bottom in `screening`. The `llm` key is special: reaching `needs_llm` always routes to the grader under that key.

## Reordering, adding, removing

Publish the config, then edit the `pipeline` array:

```bash
php artisan vendor:publish --tag=modman-config
```

```php
// config/modman.php
'pipeline' => [
    'denylist' => Dynamik\Modman\Graders\DenylistGrader::class,
    'heuristic' => Dynamik\Modman\Graders\HeuristicGrader::class,
    'openai_moderation' => Dynamik\Modman\Graders\OpenAiModerationGrader::class,
    'llm' => Dynamik\Modman\Graders\LlmGrader::class,
],
```

Removing `llm` is supported. The orchestrator will simply run the remaining graders in `screening`, and when no more apply it routes to human.

Custom graders just need a unique key and a class — see [writing-a-custom-grader.md](writing-a-custom-grader.md). Bind a constructor in a service provider if the grader needs injected dependencies (the container resolves the class).

## Ordering note: keep `'llm'` last

Place `'llm'` last in `config('modman.pipeline')`. Running graders after `'llm'` is supported (no longer infinite-loops as of task-1) but each subsequent grader will run on a separate orchestrator tick and require its own state-machine handling — the orchestrator dispatches a fresh `RunModerationPipeline` job per advance once the report is in `needs_llm`. That means more queue round-trips and more decision rows than a screening-only pipeline.

Future versions may forbid this ordering with an arch test. For now, the recommended layout is screening-only graders (denylist, heuristic, hosted classifiers) first and `'llm'` last.

## Failure handling

If a grader throws, the orchestrator catches it and writes an `error` verdict instead of blowing up the pipeline. If the queued job itself fails all three retries, `RunModerationPipeline::failed()` writes one final error decision and transitions to `needs_human` so the report never gets stuck.
