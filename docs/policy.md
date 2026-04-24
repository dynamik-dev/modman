# Policy

A policy decides what happens after a grader produces a `Decision`. It implements `Dynamik\Modman\Contracts\ModerationPolicy`:

```php
public function decide(Report $report, Decision $latest): PolicyAction;
```

Must not mutate the report or the decision. Returns one of four `PolicyAction` variants: `Approve`, `Reject`, `EscalateTo(graderKey)`, or `RouteToHuman`.

## `ConfigDrivenPolicy` (default)

`Dynamik\Modman\Policy\ConfigDrivenPolicy` reads thresholds from `config/modman.php`:

```php
'thresholds' => [
    'auto_reject_at' => 0.9,
    'auto_approve_below' => 0.2,
],
```

Its decision tree runs in this order:

1. If the latest verdict is `reject`, or severity is at or above `auto_reject_at` (default `0.9`), return `Reject`.
2. If the latest verdict is `approve` and severity is strictly below `auto_approve_below` (default `0.2`), return `Approve`.
3. Otherwise, look up the current grader's position in the configured pipeline. If there is a next grader, return `EscalateTo(nextKey)`.
4. No more graders left: return `RouteToHuman`.

`Inconclusive` verdicts at moderate severity fall through step 3 or 4 naturally. `Error` and `Skipped` verdicts also fall through — the orchestrator relies on the policy to route them onward.

### Tuning

Raise `auto_reject_at` to require higher confidence before auto-rejecting. Lower `auto_approve_below` to be more conservative about auto-approving. Anything in the middle gets passed down the pipeline or escalated to a human.

## Swapping the policy

### Option 1: set the policy class in config

```php
// config/modman.php
'policy' => App\Moderation\StrictPolicy::class,
```

`ModmanServiceProvider` reads `modman.policy` and resolves that class from the container when anything asks for `ModerationPolicy`. Your class must implement `Dynamik\Modman\Contracts\ModerationPolicy`.

### Option 2: bind in a service provider

Useful when your policy has dependencies the container should inject, or when you want full control over construction.

```php
<?php

namespace App\Providers;

use App\Moderation\StrictPolicy;
use Dynamik\Modman\Contracts\ModerationPolicy;
use Illuminate\Support\ServiceProvider;

class ModmanPolicyProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ModerationPolicy::class, function ($app): ModerationPolicy {
            return new StrictPolicy(
                pipeline: array_keys($app['config']->get('modman.pipeline', [])),
                autoRejectAt: 0.75,
                autoApproveBelow: 0.1,
                alwaysEscalateLlm: true,
            );
        });
    }
}
```

Register the provider in `bootstrap/providers.php` (Laravel 11+) or `config/app.php`.

A full worked example — a policy that never auto-approves and always routes to human after the LLM runs:

```php
<?php

namespace App\Moderation;

use Dynamik\Modman\Contracts\ModerationPolicy;
use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\PolicyAction;
use Dynamik\Modman\Support\PolicyActions\EscalateTo;
use Dynamik\Modman\Support\PolicyActions\Reject;
use Dynamik\Modman\Support\PolicyActions\RouteToHuman;

final class HumanGatedPolicy implements ModerationPolicy
{
    public function decide(Report $report, Decision $latest): PolicyAction
    {
        if ($latest->verdict === 'reject' || ($latest->severity ?? 0) >= 0.9) {
            return new Reject;
        }

        if ($latest->grader === 'denylist') {
            return new EscalateTo('llm');
        }

        return new RouteToHuman;
    }
}
```

```php
$this->app->bind(ModerationPolicy::class, fn () => new HumanGatedPolicy);
```

See [writing-a-custom-policy.md](writing-a-custom-policy.md) for more detail on the contract and the four `PolicyAction` variants.
