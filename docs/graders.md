# Graders

A grader evaluates a `ModerationContent` and returns a `Verdict`. It implements `Dynamik\Modman\Contracts\Grader`:

```php
public function key(): string;
public function supports(ModerationContent $content): bool;
public function grade(ModerationContent $content, Report $report): Verdict;
```

The `Verdict` carries a `VerdictKind` (`Approve`, `Reject`, `Inconclusive`, `Error`), a severity in `[0.0, 1.0]`, a human-readable reason, and arbitrary evidence. The `Tier` records where in the stack the grader runs: `Denylist`, `Heuristic`, `HostedClassifier`, `Llm`, `Human`.

`VerdictKind::Skipped` also exists, but is reserved for the orchestrator: it is written when `Grader::supports()` returns false so the audit trail explains why the pipeline advanced. Graders themselves should never return `Skipped` — they should signal non-applicability via `supports()`.

## Shipped graders

### `DenylistGrader`

- Key: `denylist`
- Tier: `Denylist`
- Default: **on** (first in the default pipeline)

Matches literal words (after transliteration + lowercasing by default) and PCRE patterns against text content. Hits return a reject at severity `0.95`; no hits return approve at `0.0`.

```php
// config/modman.php
'graders' => [
    'denylist' => [
        'words' => ['badword1', 'badword2'],
        'words_path' => resource_path('modman/denylist.txt'),
        'regex' => ['/\bspam\b/i'],
        'case_sensitive' => false,
    ],
],
```

Words in `words_path` are merged with inline `words`. Lines starting with `#` are treated as comments. The service provider binds the `DenylistGrader::class` to read these config keys.

The `case_sensitive` flag and unicode-confusables transliteration apply to `words` only. `regex` patterns match against the raw text exactly as supplied — express case-insensitivity with the `/i` PCRE flag (e.g. `'/\\bbadword\\b/i'`). This keeps full PCRE control in the caller's hands rather than silently lowercasing capture groups.

Use when: you have a known-bad terms list. Fast, local, no API calls.

### `LlmGrader`

- Key: `llm`
- Tier: `Llm`
- Default: **on** (second in the default pipeline)

Calls an LLM (Anthropic or OpenAI) with your prompt template. Expects the model to return JSON with `verdict`, `severity`, `reason`, and optional `categories`. Parse errors produce an `Error` verdict; network failures too.

```php
'graders' => [
    'llm' => [
        'driver' => env('MODMAN_LLM_DRIVER', 'anthropic'),
        'model' => env('MODMAN_LLM_MODEL', 'claude-haiku-4-5'),
        'prompt' => resource_path('modman/prompts/grader.md'),
        'max_tokens' => 512,
        'timeout' => 15,
        'api_key' => env('MODMAN_LLM_API_KEY'),
    ],
],
```

Your prompt file should include the placeholder `{{content}}`; the grader substitutes the rendered content there. Supported drivers are `anthropic` and `openai`.

Use when: you need nuanced judgment the denylist cannot express. Costs money; keep it behind cheaper tiers.

### `HeuristicGrader`

- Key: `heuristic`
- Tier: `Heuristic`
- Default: **opt-in off**

Stateless rule-based text signals: all caps (>80 percent of letters), link density (three or more URLs in under 200 chars), and long repeated-character runs. Returns `Inconclusive` with a severity based on how many signals fired.

No config. Register in the pipeline to enable:

```php
'pipeline' => [
    'denylist' => Dynamik\Modman\Graders\DenylistGrader::class,
    'heuristic' => Dynamik\Modman\Graders\HeuristicGrader::class,
    'llm' => Dynamik\Modman\Graders\LlmGrader::class,
],
```

Use when: you want a cheap extra signal between denylist and LLM to catch obvious spam patterns before spending tokens.

### `OpenAiModerationGrader`

- Key: `openai_moderation`
- Tier: `HostedClassifier`
- Default: **opt-in off**

Calls the OpenAI `/v1/moderations` endpoint. Flagged responses become `Reject` with severity equal to the highest category score; unflagged become `Approve` with the same score as a severity signal.

```php
use Dynamik\Modman\Graders\OpenAiModerationGrader;

$this->app->bind(OpenAiModerationGrader::class, fn () => new OpenAiModerationGrader(
    apiKey: env('OPENAI_API_KEY'),
    model: 'omni-moderation-latest',
    timeout: 10,
));
```

Then add `'openai_moderation' => OpenAiModerationGrader::class` to `pipeline`.

Use when: you want a cheap hosted classifier as a safety net. The free tier covers many apps.

### `PerceptualHashGrader`

- Key: `perceptual_hash`
- Tier: `HostedClassifier`
- Default: **opt-in off**

Compares each image in the content against a list of known-bad perceptual hashes. You inject the hasher closure that turns an `Image` into a hash string; matches return reject at severity `1.0`.

```php
use Dynamik\Modman\Graders\PerceptualHashGrader;

$this->app->bind(PerceptualHashGrader::class, fn () => new PerceptualHashGrader(
    knownHashes: config('modman.known_image_hashes', []),
    hasher: fn ($image) => MyHasher::hash($image->url),
));
```

`supports()` returns false when no hasher is configured or there are no images.

Use when: you have a corpus of known-bad images and a perceptual-hash implementation (pHash, dHash, etc.) on hand.

## Testing utilities

### `FakeGrader`

`Dynamik\Modman\Graders\Testing\FakeGrader`. Returns a canned verdict. Useful for wiring into the pipeline in tests.

```php
use Dynamik\Modman\Graders\Testing\FakeGrader;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;

$grader = new FakeGrader(
    key: 'test',
    verdict: new Verdict(VerdictKind::Reject, 0.95, 'forced reject'),
    supports: true,
);
```

### `RecordingGrader`

`Dynamik\Modman\Graders\Testing\RecordingGrader`. Wraps another grader and records every `grade()` call in `$recording->calls`.

```php
use Dynamik\Modman\Graders\Testing\RecordingGrader;

$recording = new RecordingGrader(new FakeGrader);
$recording->grade($content, $report);

expect($recording->calls)->toHaveCount(1);
```

## Conformance

Every grader (shipped or custom) must pass the conformance suite in `tests/Conformance/GraderConformance.php`. It asserts:

- `key()` is stable across instances and non-empty.
- `supports()` never throws on empty content.
- When `supports()` returns true, `grade()` returns a verdict with `severity` in `[0.0, 1.0]`.

Run against a custom grader with:

```php
it('MyCustomGrader conforms', function (): void {
    assertGraderConforms(fn () => new MyCustomGrader(...));
});
```
