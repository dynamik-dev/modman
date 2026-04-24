# Writing a custom grader

A grader is any class that implements `Dynamik\Modman\Contracts\Grader`.

## The contract

```php
<?php

namespace Dynamik\Modman\Contracts;

use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;

interface Grader
{
    /** Stable alias used in config (e.g. 'denylist', 'llm'). */
    public function key(): string;

    /** True if this grader can evaluate this content. Must not throw. */
    public function supports(ModerationContent $content): bool;

    /** Called only when supports() returned true. May throw; the orchestrator catches. */
    public function grade(ModerationContent $content, Report $report): Verdict;
}
```

`key()` must be stable across instances — two `new MyGrader(...)` calls must return the same string. The string becomes the `grader` column in `moderation_decisions`, so keep it short and URL-safe.

`supports()` must never throw. If your grader only handles text and the content has only images, return false. The orchestrator will write a `skipped` decision and move on.

`grade()` returns a `Verdict` with a `VerdictKind`, a severity in `[0.0, 1.0]`, a human reason, and optional evidence. Throwing is fine — the orchestrator converts it to an `Error` verdict.

## Minimal example

A grader that rejects anything longer than a configured maximum length:

```php
<?php

namespace App\Moderation;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;

final class MaxLengthGrader implements Grader
{
    public function __construct(private readonly int $maxChars = 2000) {}

    public function key(): string
    {
        return 'max_length';
    }

    public function supports(ModerationContent $content): bool
    {
        return $content->hasText();
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        $len = mb_strlen((string) $content->text());
        if ($len > $this->maxChars) {
            return new Verdict(
                VerdictKind::Reject,
                0.85,
                "text is {$len} chars, max {$this->maxChars}",
                ['length' => $len],
            );
        }

        return new Verdict(VerdictKind::Approve, 0.0, 'within length limit');
    }
}
```

## Registering

Add it to the `pipeline` map in `config/modman.php`. The array is keyed by grader key; the value is the fully qualified class name.

```php
'pipeline' => [
    'denylist' => Dynamik\Modman\Graders\DenylistGrader::class,
    'max_length' => App\Moderation\MaxLengthGrader::class,
    'llm' => Dynamik\Modman\Graders\LlmGrader::class,
],
```

Order matters: graders run top to bottom while the report is in `screening`. The orchestrator resolves each class from the container (`app()->make($class)`), so autowiring works. If your grader needs config-driven construction, bind it explicitly in a service provider:

```php
$this->app->bind(App\Moderation\MaxLengthGrader::class, fn () => new MaxLengthGrader(
    maxChars: (int) config('moderation.max_chars', 2000),
));
```

## Conformance

The test suite in `tests/Conformance/GraderConformance.php` exposes `assertGraderConforms()`, which asserts the three behaviors every grader must satisfy. Call it with a factory closure:

```php
use function assertGraderConforms;

it('MaxLengthGrader conforms', function (): void {
    assertGraderConforms(fn () => new \App\Moderation\MaxLengthGrader(2000));
});
```

The suite confirms that:

- `key()` is stable across instances and non-empty.
- `supports(empty content)` never throws and returns a boolean.
- When `supports()` is true, `grade()` returns a verdict with severity in `[0.0, 1.0]`.

If you publish a package of graders, ship a conformance test alongside it so consumers can see it pass on their Laravel version.
