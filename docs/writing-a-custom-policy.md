# Writing a custom policy

A policy is any class that implements `Dynamik\Modman\Contracts\ModerationPolicy`.

## The contract

```php
<?php

namespace Dynamik\Modman\Contracts;

use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\PolicyAction;

interface ModerationPolicy
{
    /**
     * Decide what to do after a grader has produced a Decision.
     * Must not mutate the Report or Decision — returns an action only.
     */
    public function decide(Report $report, Decision $latest): PolicyAction;
}
```

A policy is called once per grader verdict. The orchestrator applies the returned `PolicyAction` and, if the pipeline is still running, queues the next iteration.

## The four `PolicyAction` variants

All live under `Dynamik\Modman\Support\PolicyActions`. `PolicyAction` is a marker interface — a sealed union of four classes.

| Class | Effect |
| --- | --- |
| `Approve` | Transition to `resolved_approved`, fire `ReportResolved`. |
| `Reject` | Transition to `resolved_rejected`, fire `ReportResolved`. |
| `EscalateTo(string $graderKey)` | Continue the pipeline. If the key is `llm`, transition to `needs_llm` first. Queue another run. |
| `RouteToHuman` | Transition to `needs_human`, fire `ReportAwaitingHuman`. |

Only `EscalateTo` carries data:

```php
final readonly class EscalateTo implements PolicyAction
{
    public function __construct(public string $graderKey) {}
}
```

The other three are tag-only value classes:

```php
new Approve;
new Reject;
new RouteToHuman;
new EscalateTo('llm');
```

## Minimal example

A policy that rejects obvious hits from the denylist, auto-approves clearly clean content, and sends everything else to a human without hitting the LLM:

```php
<?php

namespace App\Moderation;

use Dynamik\Modman\Contracts\ModerationPolicy;
use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\PolicyAction;
use Dynamik\Modman\Support\PolicyActions\Approve;
use Dynamik\Modman\Support\PolicyActions\Reject;
use Dynamik\Modman\Support\PolicyActions\RouteToHuman;

final class CheapPolicy implements ModerationPolicy
{
    public function decide(Report $report, Decision $latest): PolicyAction
    {
        if ($latest->verdict === 'reject') {
            return new Reject;
        }

        if ($latest->verdict === 'approve' && ($latest->severity ?? 1.0) < 0.1) {
            return new Approve;
        }

        return new RouteToHuman;
    }
}
```

## Binding in a service provider

Option 1: set `'policy' => CheapPolicy::class` in `config/modman.php`. `ModmanServiceProvider` resolves that class when anything asks the container for `ModerationPolicy`.

Option 2: bind explicitly — lets you inject dependencies and keeps your config simpler.

```php
<?php

namespace App\Providers;

use App\Moderation\CheapPolicy;
use Dynamik\Modman\Contracts\ModerationPolicy;
use Illuminate\Support\ServiceProvider;

final class ModmanPolicyProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ModerationPolicy::class, fn () => new CheapPolicy);
    }
}
```

Register the provider in `bootstrap/providers.php` (Laravel 11+) or `config/app.php`. Your binding wins over the default `ConfigDrivenPolicy` because the package's binding is `bind`, not `singleton` — the last registered wins.

Policies should be deterministic and side-effect-free. If you need to log, emit metrics, or notify someone, listen to `GraderRan` or `ReportResolved` instead — see [events.md](events.md).
