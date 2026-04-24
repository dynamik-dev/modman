# Events

modman is telemetry-agnostic: the package dispatches events and lets you wire your own logging, metrics, notifications, or UI updates. All events live under `Dynamik\Modman\Events`. They are plain final readonly classes, not `ShouldBroadcast`.

## `ReportCreated`

Fired immediately after a new `Report` row is inserted by `$model->report(...)`.

```php
public function __construct(public Report $report) {}
```

```php
use Dynamik\Modman\Events\ReportCreated;

class LogNewReports
{
    public function handle(ReportCreated $event): void
    {
        logger()->info('report.created', [
            'report_id' => $event->report->id,
            'reportable' => $event->report->reportable_type,
        ]);
    }
}
```

## `ReportTransitioned`

Fired by `LoggedTransition::handle()` after every state change — `pending -> screening`, `needs_llm -> needs_human`, `resolved_approved -> needs_human` (reopen), and so on.

```php
public function __construct(
    public Report $report,
    public string $from,
    public string $to,
) {}
```

```php
use Dynamik\Modman\Events\ReportTransitioned;

class AuditStateChanges
{
    public function handle(ReportTransitioned $event): void
    {
        Audit::log("report {$event->report->id}: {$event->from} -> {$event->to}");
    }
}
```

## `GraderRan`

Fired by the orchestrator after it persists a `Decision`.

```php
public function __construct(
    public Report $report,
    public Decision $decision,
) {}
```

```php
use Dynamik\Modman\Events\GraderRan;

class MeterGraderLatency
{
    public function handle(GraderRan $event): void
    {
        Metrics::increment('modman.grader.ran', [
            'grader' => $event->decision->grader,
            'verdict' => $event->decision->verdict,
        ]);
    }
}
```

## `ReportAwaitingHuman`

Fired when the orchestrator transitions a report into `needs_human` (either because the policy returned `RouteToHuman` or the pipeline exhausted all graders).

```php
public function __construct(public Report $report) {}
```

```php
use Dynamik\Modman\Events\ReportAwaitingHuman;

class NotifyModerators
{
    public function handle(ReportAwaitingHuman $event): void
    {
        Notification::route('slack', config('moderation.slack_webhook'))
            ->notify(new ModerationNeededNotification($event->report));
    }
}
```

## `ReportResolved`

Fired after a report lands in a terminal state — either via the pipeline auto-resolving, or via `$report->resolveApprove(...)` / `$report->resolveReject(...)`.

```php
public function __construct(
    public Report $report,
    public VerdictKind $outcome,
) {}
```

The `$outcome` is `VerdictKind::Approve` or `VerdictKind::Reject`.

```php
use Dynamik\Modman\Events\ReportResolved;
use Dynamik\Modman\Support\VerdictKind;

class HideRejectedContent
{
    public function handle(ReportResolved $event): void
    {
        if ($event->outcome === VerdictKind::Reject) {
            $event->report->reportable?->update(['hidden_at' => now()]);
        }
    }
}
```

## `ReportReopened`

Fired after `$report->reopen(...)` transitions a terminal report back to `needs_human`.

```php
public function __construct(
    public Report $report,
    public ?Model $actor,
    public ?string $reason,
) {}
```

```php
use Dynamik\Modman\Events\ReportReopened;

class LogReopens
{
    public function handle(ReportReopened $event): void
    {
        logger()->warning('report.reopened', [
            'report_id' => $event->report->id,
            'actor' => $event->actor?->getKey(),
            'reason' => $event->reason,
        ]);
    }
}
```

## Registering listeners

Laravel's event discovery will pick up typed `handle()` methods automatically. Otherwise register explicitly in a service provider:

```php
use Dynamik\Modman\Events\ReportResolved;
use Illuminate\Support\Facades\Event;

Event::listen(ReportResolved::class, HideRejectedContent::class);
```
