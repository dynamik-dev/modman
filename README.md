# modman

Headless Laravel content moderation. Users flag content, a tiered grader pipeline (denylist, LLM, optional heuristics and hosted classifiers) evaluates it, and a configurable policy either auto-resolves the report or routes it to a human.

modman ships no UI. It dispatches events and exposes three HTTP endpoints; you wire the rest.

## Install

```bash
composer require dynamik-dev/modman
php artisan vendor:publish --tag=modman-migrations
php artisan vendor:publish --tag=modman-config
php artisan migrate
```

Optional: publish the default denylist and LLM prompt resources.

```bash
php artisan vendor:publish --tag=modman-resources
```

Set queue env vars if you do not want the default `modman` queue:

```dotenv
MODMAN_QUEUE=modman
MODMAN_QUEUE_CONNECTION=redis
MODMAN_LLM_DRIVER=anthropic
MODMAN_LLM_MODEL=claude-haiku-4-5
MODMAN_LLM_API_KEY=sk-ant-...
```

## One-page example

Make a host model reportable:

```php
<?php

namespace App\Models;

use Dynamik\Modman\Concerns\Reportable as ReportableTrait;
use Dynamik\Modman\Contracts\Reportable;
use Dynamik\Modman\Support\ModerationContent;
use Illuminate\Database\Eloquent\Model;

class Post extends Model implements Reportable
{
    use ReportableTrait;

    public function toModerationContent(): ModerationContent
    {
        return ModerationContent::make()->withText($this->body);
    }
}
```

Let a user flag a post:

```php
$post = Post::find($postId);
$report = $post->report(reporter: auth()->user(), reason: 'Spam');
```

That creates a `Report` in the `pending` state, fires `ReportCreated`, and queues `RunModerationPipeline`. The orchestrator walks the configured pipeline (denylist then LLM by default) until the policy either auto-resolves the report or routes it to a human.

React to the outcome:

```php
<?php

namespace App\Listeners;

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

Register the listener however you prefer (Laravel event discovery, a provider, etc.).

## HTTP endpoints

The package registers three routes under the prefix `modman`, defaulting to the `api` and `auth` middleware. Reports return moderator-only data (free-text reasons, LLM evidence, full decision history), so the routes ship behind authentication and an explicit authorization gate.

| Method | Path | Name |
| --- | --- | --- |
| GET | `/modman/reports/{report}` | `modman.reports.show` |
| POST | `/modman/reports/{report}/resolve` | `modman.reports.resolve` |
| POST | `/modman/reports/{report}/reopen` | `modman.reports.reopen` |

`resolve` takes `{ "decision": "approve" | "reject", "reason": "optional string" }`. `reopen` takes an optional `reason`. Both require `$request->user()` to be an Eloquent `Model` and to pass the matching gate.

### Disabling or overriding the routes

```php
// config/modman.php
'routes' => [
    'enabled' => true,                    // set false to skip route registration entirely
    'middleware' => ['api', 'auth:sanctum'], // override to match your guard stack
],
```

When `routes.enabled` is `false`, the package registers no HTTP routes — wire your own controllers if you need a different shape.

### Authorization gates

modman defines two gates with fail-closed defaults:

- `modman.resolve` — checked before `POST /modman/reports/{report}/resolve`
- `modman.reopen` — checked before `POST /modman/reports/{report}/reopen`

Both deny by default (every request returns 403) until the host overrides them. Register replacements in any service provider that boots before `ModmanServiceProvider`, or simply at runtime — `Gate::has()` keeps the package from clobbering your definition:

```php
use Dynamik\Modman\Models\Report;
use Illuminate\Support\Facades\Gate;
use App\Models\User;

Gate::define('modman.resolve', fn (User $user, Report $report) => $user->is_moderator);
Gate::define('modman.reopen', fn (User $user, Report $report) => $user->is_moderator);
```

The controllers respond with 401 when no user is authenticated, 403 when the authenticated identity is not an Eloquent `Model` or the gate denies the action.

## Where to go next

- [`docs/pipeline.md`](docs/pipeline.md) — the finite-state machine and how to reorder the pipeline
- [`docs/graders.md`](docs/graders.md) — every shipped grader and its config
- [`docs/policy.md`](docs/policy.md) — `ConfigDrivenPolicy` and how to swap it
- [`docs/events.md`](docs/events.md) — every event and a listener example
- [`docs/writing-a-custom-grader.md`](docs/writing-a-custom-grader.md)
- [`docs/writing-a-custom-policy.md`](docs/writing-a-custom-policy.md)
- [`docs/adr/`](docs/adr/) — architectural decision records

## Requirements

- PHP 8.3+
- Laravel 11 or 12
- spatie/laravel-model-states ^2.7

## License

MIT.
