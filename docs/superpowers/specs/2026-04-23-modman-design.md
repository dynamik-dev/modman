# modman — Design Spec

- **Date:** 2026-04-23
- **Status:** Draft, ready for review
- **Author:** Chris Arter (with Claude)
- **Target repo:** `~/Documents/projects/modman`
- **Quality bar reference:** [`marque-ts`](../../../../marque-ts/docs/superpowers/specs/2026-04-10-marque-ts-design.md) — driver ports, conformance suites, ephemeral state, strict tooling.

---

## 1. Purpose and positioning

`modman` is a Laravel package for moderating user-generated content (text and images; video deferred to a later release). It models moderation as a finite-state machine: a `Report` flows through a tiered pipeline of graders from cheapest and most deterministic to most expensive, and only content that genuinely needs judgement reaches a human.

Inspiration: Perspective API (Jigsaw), OpenAI Moderation, AWS Rekognition content moderation, Cloudflare AI Gateway, Reddit AutoModerator, Discord AutoMod, Hive, Sift. The package does not replace any one of these — it composes them behind a uniform `Grader` contract and adds the FSM orchestration, persistence, and event surface that Laravel apps need.

### Goals

- **Cheap-first, deterministic-first**: denylists and heuristics run before anything stochastic.
- **Headless and driver-shaped**: core ships no UI. Every stage (grader, policy, queue, notification) is a swappable port.
- **Tunable without forks**: word lists, prompts, thresholds, provider choice, and pipeline order are all config.
- **Eloquent-native**: any model with the `Reportable` trait is moderatable. No tight coupling to Spatie Media Library, Sanctum, or a specific user model.
- **Observable by default**: every transition emits a Laravel event. Consumers wire OTel, logs, Slack, or metrics on their side.
- **Ephemeral state**: every piece of runtime state is independently constructible in a test. No shared fixtures, no seeders, no global mutation.
- **Quality bar parity with `marque-ts`**: strict static analysis, conformance tests, architecture tests.

### Non-goals for v1

- Admin UI (headless only; a Filament companion package may follow).
- Video moderation (the `ModerationContent` contract reserves the slot; graders can opt-out via `supports()`).
- Multi-tenant scoping (the package runs inside a single Laravel app; multi-tenancy is a consumer concern).
- Custom DSL for rules (the `ModerationPolicy` interface is the escape hatch; consumers swap the class).
- No bundled hash database for CSAM / known-bad-image matching. The package ships a generic `PerceptualHashGrader` that checks configured hashes; sourcing and maintaining the hash set is the consumer's responsibility.
- OpenTelemetry as a hard dependency.
- Distributed/durable workflow engines (Temporal, Laravel Workflow). Queue jobs + FSM are sufficient.

---

## 2. Package topology

Single Composer package: **`dynamik-dev/modman`**. PHP 8.3+, Laravel 11 and 12 supported.

```
modman/
├── composer.json
├── config/
│   └── modman.php                     # published via vendor:publish
├── database/
│   ├── migrations/                    # reports, decisions, moderation_transitions
│   └── factories/                     # ReportFactory, DecisionFactory, TestReportableFactory
├── resources/
│   ├── modman/
│   │   ├── denylist.txt               # default word list (empty; publishable)
│   │   └── prompts/
│   │       └── grader.md              # default LLM rubric (publishable)
├── routes/
│   └── api.php                        # report creation + human resolution endpoints
├── src/
│   ├── ModmanServiceProvider.php
│   ├── Contracts/                     # interfaces only
│   │   ├── Grader.php
│   │   ├── ModerationPolicy.php
│   │   └── Reportable.php
│   ├── Support/                       # pure value objects; no DB, no HTTP
│   │   ├── ModerationContent.php
│   │   ├── Image.php
│   │   ├── Video.php                  # reserved for future
│   │   ├── Verdict.php
│   │   ├── Tier.php                   # enum
│   │   ├── VerdictKind.php            # enum
│   │   └── PolicyAction.php           # sealed union: Approve|Reject|EscalateTo|RouteToHuman
│   ├── Models/
│   │   ├── Report.php
│   │   └── Decision.php
│   ├── Concerns/
│   │   └── Reportable.php             # the trait
│   ├── States/                        # spatie/laravel-model-states
│   │   ├── ReportState.php
│   │   ├── Pending.php
│   │   ├── Screening.php
│   │   ├── NeedsLlm.php
│   │   ├── NeedsHuman.php
│   │   ├── ResolvedApproved.php
│   │   └── ResolvedRejected.php
│   ├── Transitions/                   # spatie transition classes
│   ├── Graders/
│   │   ├── DenylistGrader.php         # default-enabled
│   │   ├── LlmGrader.php              # default-enabled (Anthropic/OpenAI)
│   │   ├── HeuristicGrader.php        # shipped, opt-in
│   │   ├── OpenAiModerationGrader.php # shipped, opt-in
│   │   ├── PerceptualHashGrader.php   # shipped, opt-in
│   │   └── Testing/
│   │       ├── FakeGrader.php
│   │       └── RecordingGrader.php
│   ├── Policy/
│   │   └── ConfigDrivenPolicy.php     # default ModerationPolicy
│   ├── Jobs/
│   │   └── RunModerationPipeline.php
│   ├── Pipeline/
│   │   └── Orchestrator.php           # drives one grader run + policy decision + next-state transition
│   ├── Events/
│   │   ├── ReportCreated.php
│   │   ├── ReportTransitioned.php
│   │   ├── GraderRan.php
│   │   ├── ReportAwaitingHuman.php
│   │   ├── ReportResolved.php
│   │   └── ReportReopened.php
│   └── Http/
│       ├── Controllers/
│       └── Resources/
└── tests/
    ├── Pest.php
    ├── TestCase.php                   # extends Orchestra\Testbench
    ├── Arch/                          # Pest architecture tests
    ├── Unit/
    ├── Feature/
    └── Conformance/                   # Grader conformance suite (reusable)
```

Rules:

- **Zero hard dep on any AI provider SDK.** HTTP calls use Laravel's `Http::` facade so `Http::fake()` works and no transitive SDK pulls in a vendor-lock concern.
- **Required runtime deps:** `spatie/laravel-model-states`, `illuminate/*`. Nothing else that a Laravel app doesn't already have.
- **Suggested deps** (per driver): SDKs are `composer suggest`, guarded by `class_exists()` checks where useful. Default graders use `Http::` directly; SDKs are optional sugar.

---

## 3. Core abstractions

### 3.1 `Reportable` trait

Applied to any Eloquent model. Provides:

- `reports(): MorphMany` — the report history for this record.
- `report(Model $reporter, string $reason): Report` — creates a `Report` row, dispatches `RunModerationPipeline`.
- Requires the host model to implement `toModerationContent(): ModerationContent`.

```php
use Dynamik\Modman\Concerns\Reportable;
use Dynamik\Modman\Support\ModerationContent;

class Post extends Model
{
    use Reportable;

    public function toModerationContent(): ModerationContent
    {
        return ModerationContent::make()
            ->withText($this->body)
            ->withImages($this->media->map(fn ($m) => new Image($m->getUrl())));
    }
}
```

### 3.2 `ModerationContent` value object

Immutable. Builder methods return new instances. Holds text, images, videos (reserved). Graders call `supports()` against this to decide whether to participate.

### 3.3 `Grader` contract

```php
interface Grader
{
    public function key(): string;                                  // stable alias; matches config
    public function supports(ModerationContent $content): bool;
    public function grade(ModerationContent $content, Report $report): Verdict;
}
```

- Stateless. No mutable instance properties.
- Must not throw for unsupported content — return `false` from `supports()` instead. If `supports()` returned `true` and `grade()` still fails, the orchestrator catches the exception and records a `Verdict(error, ...)`.

### 3.4 `Verdict` value object

```php
final readonly class Verdict
{
    public function __construct(
        public VerdictKind $kind,      // approve | reject | inconclusive | error
        public float $severity,        // 0.0–1.0
        public string $reason,
        public array $evidence = [],   // grader-specific JSON payload
    ) {}
}
```

Persisted as a `Decision` row.

### 3.5 `ModerationPolicy` contract

```php
interface ModerationPolicy
{
    public function decide(Report $report, Decision $latest): PolicyAction;
}
```

`PolicyAction` is a sealed union:

- `Approve` — transition to `ResolvedApproved`.
- `Reject` — transition to `ResolvedRejected`.
- `EscalateTo(string $graderKey)` — transition to the state matching the grader tier; dispatch the next job.
- `RouteToHuman` — transition to `NeedsHuman`; stop.

Default implementation `ConfigDrivenPolicy` uses per-tier thresholds from `config/modman.php`:

- `severity >= auto_reject_at` → `Reject`.
- `severity < auto_approve_below` → `Approve`.
- Otherwise → next grader in the pipeline. If there is no next grader, `RouteToHuman`.

Consumers can bind a different class for `ModerationPolicy::class` in their service provider for full control.

### 3.6 `Orchestrator`

Drives one tick of the pipeline:

1. Load the report + latest state.
2. Pick the grader for the current state.
3. Call `supports()`; if false, advance to the next grader (no decision written).
4. Call `grade()` inside a try/catch; persist a `Decision`.
5. Fire `GraderRan`.
6. Ask `ModerationPolicy::decide()`.
7. Transition via spatie; fire `ReportTransitioned`.
8. If action escalates, dispatch the next `RunModerationPipeline` job. Otherwise terminate (fire `ReportResolved` or `ReportAwaitingHuman`).

### 3.7 State machine

Managed by `spatie/laravel-model-states` on the `Report` model.

```
pending
  └─> screening
        ├─> resolved_approved
        ├─> resolved_rejected
        └─> needs_llm
              ├─> resolved_approved
              ├─> resolved_rejected
              └─> needs_human
                    ├─> resolved_approved
                    └─> resolved_rejected

resolved_approved / resolved_rejected
  └─> needs_human         (via $report->reopen())
```

Every transition class writes the transition metadata (from, to, actor, reason) into `moderation_transitions` and fires `ReportTransitioned`.

---

## 4. Data model

### 4.1 `reports`

| column           | type                      | notes                                   |
|------------------|---------------------------|-----------------------------------------|
| id               | ulid PK                   |                                         |
| reportable_type  | string                    | polymorphic target                      |
| reportable_id    | ulid/uuid/bigint          | host app's PK shape                     |
| reporter_type    | string, nullable          | polymorphic reporter                    |
| reporter_id      | nullable                  |                                         |
| reason           | string, nullable          | user-supplied reason                    |
| state            | string                    | spatie state FQCN                       |
| resolved_at      | timestamp, nullable       |                                         |
| created_at       | timestamp                 |                                         |
| updated_at       | timestamp                 |                                         |

Index on `(reportable_type, reportable_id)` and `(state)`.

### 4.2 `moderation_decisions` (append-only)

| column     | type               | notes                                                      |
|------------|--------------------|------------------------------------------------------------|
| id         | ulid PK            |                                                            |
| report_id  | ulid FK            | `reports.id`, cascade on delete                            |
| grader     | string             | grader alias (`denylist`, `llm`, `human`, …)               |
| tier       | string (enum)      | `denylist | heuristic | hosted_classifier | llm | human`   |
| verdict    | string (enum)      | `approve | reject | inconclusive | error`                  |
| severity   | float, nullable    | 0.0–1.0                                                    |
| reason     | text, nullable     |                                                            |
| evidence   | json, nullable     | grader-specific payload                                    |
| actor_type | string, nullable   | human decisions carry the moderator                        |
| actor_id   | nullable           |                                                            |
| created_at | timestamp          |                                                            |

Immutable once written. No `updated_at`. Index on `(report_id, created_at)`.

### 4.3 `moderation_transitions` (append-only)

| column     | type               |
|------------|--------------------|
| id         | ulid PK            |
| report_id  | ulid FK            |
| from_state | string             |
| to_state   | string             |
| actor_type | string, nullable   |
| actor_id   | nullable           |
| reason     | text, nullable     |
| created_at | timestamp          |

Index on `(report_id, created_at)`.

---

## 5. Event surface

All events are `final readonly` plain PHP objects (no Laravel base class required).

| Event                    | Payload                                                   |
|--------------------------|-----------------------------------------------------------|
| `ReportCreated`          | `Report`                                                  |
| `ReportTransitioned`     | `Report`, `string $from`, `string $to`                    |
| `GraderRan`              | `Report`, `Decision`                                      |
| `ReportAwaitingHuman`    | `Report`                                                  |
| `ReportResolved`         | `Report`, `VerdictKind` (approve or reject)               |
| `ReportReopened`         | `Report`, actor                                           |

Consumers wire listeners via Laravel's `EventServiceProvider`. OTel, logs, Slack, Filament notifications — all user code.

---

## 6. Flows

### 6.1 Happy path (clear approve)

1. `$post->report($user, 'spam')` — `Report` inserted in `Pending`. `ReportCreated` fired.
2. Observer dispatches `RunModerationPipeline`.
3. Job transitions `Pending → Screening`. `ReportTransitioned`.
4. `DenylistGrader` returns `Verdict(approve, 0.0, ...)`. `Decision` persisted. `GraderRan`.
5. `ConfigDrivenPolicy` → `Approve`. Transition `Screening → ResolvedApproved`. `ReportResolved`.

### 6.2 Escalation path (LLM → human)

1–3. As above.
4. `DenylistGrader` returns `inconclusive` (no match, low confidence).
5. Policy → `EscalateTo('llm')`. Transition `Screening → NeedsLlm`. Next job dispatched.
6. `LlmGrader` returns `inconclusive` with severity in the uncertain band.
7. Policy → `RouteToHuman`. Transition `NeedsLlm → NeedsHuman`. `ReportAwaitingHuman`.
8. Pipeline terminates. Consumer's listener notifies moderators however they want.

### 6.3 Human resolution

`$report->resolveApprove($moderator, $reason)` / `resolveReject(...)` — writes a `Decision(tier=human)`, transitions to `ResolvedApproved` / `ResolvedRejected`, fires `ReportResolved`.

### 6.4 Reopen

`$report->reopen($actor, $reason)` — `ResolvedApproved | ResolvedRejected → NeedsHuman`. Useful for appeals or policy updates.

### 6.5 Grader errors

Thrown exception inside `grade()` → caught by orchestrator → `Decision(verdict=error, evidence=[class, message])` → policy default is to escalate to next tier; if none, route to human. Never silently dropped. Queue-level retries use Laravel's built-in retry config per job.

---

## 7. Configuration

`config/modman.php`:

```php
return [
    'queue' => env('MODMAN_QUEUE', 'modman'),
    'connection' => env('MODMAN_QUEUE_CONNECTION', null),

    'pipeline' => [
        'denylist' => \Dynamik\Modman\Graders\DenylistGrader::class,
        'llm'      => \Dynamik\Modman\Graders\LlmGrader::class,
    ],

    'policy' => \Dynamik\Modman\Policy\ConfigDrivenPolicy::class,

    'thresholds' => [
        'auto_reject_at'     => 0.9,
        'auto_approve_below' => 0.2,
    ],

    'graders' => [
        'denylist' => [
            'words' => [],
            'words_path' => resource_path('modman/denylist.txt'),
            'regex' => [],
            'case_sensitive' => false,
        ],
        'llm' => [
            'driver' => env('MODMAN_LLM_DRIVER', 'anthropic'),
            'model'  => env('MODMAN_LLM_MODEL', 'claude-haiku-4-5'),
            'prompt' => resource_path('modman/prompts/grader.md'),
            'max_tokens' => 512,
            'timeout' => 15,
        ],
    ],

    'reportable' => [
        'reporter_model' => \App\Models\User::class,
    ],
];
```

### 7.1 Tunability surfaces

| What                      | Where                                                       |
|---------------------------|-------------------------------------------------------------|
| Word list / regex         | `config/modman.php` or `resources/modman/denylist.txt`      |
| LLM prompt                | `resources/modman/prompts/grader.md` (publishable markdown) |
| LLM provider / model      | `config/modman.php` + env                                   |
| Thresholds                | `config/modman.php`                                         |
| Whole policy              | Bind `ModerationPolicy::class` in a service provider        |
| Pipeline order / members  | `config/modman.php` `pipeline` array                        |
| Custom grader             | Implement `Grader`, add to `pipeline` config                |
| Queue / connection        | env                                                         |
| Reactions to events       | `EventServiceProvider` listeners                            |

### 7.2 Default prompt (publishable)

```
You are a content moderator. Given the CONTENT below, return a JSON object with:
- verdict: "approve" | "reject" | "inconclusive"
- severity: float 0.0 (benign) to 1.0 (severe)
- reason: short human-readable explanation
- categories: array from ["hate","harassment","sexual","violence","self_harm","spam","other"]

POLICY:
- Reject: clear hate speech, threats, sexualized minors, doxxing.
- Inconclusive: ambiguous, sarcastic, context-dependent.
- Approve: otherwise.

Return JSON only. No prose.

CONTENT:
{{content}}
```

---

## 8. Graders

### 8.1 Default-enabled

- **`DenylistGrader`** (text). Normalizes (lowercase, Unicode confusables → ASCII via `Transliterator`), checks word list and regex, severity = max hit weight. No network. Deterministic.
- **`LlmGrader`** (text + images). Uses `Http::` to call Anthropic or OpenAI. Structured JSON response validated by a schema in code. Timeout and retries honored. Parse failure → `Verdict(error)`.

### 8.2 Shipped, opt-in

- **`HeuristicGrader`** (text). All-caps ratio, link density, repeated-char runs, Unicode confusables, emoji spam.
- **`OpenAiModerationGrader`** (text + images). Calls the free `omni-moderation` endpoint.
- **`PerceptualHashGrader`** (images). pHash against a configured set of known-bad hashes.

### 8.3 Testing

- **`FakeGrader`** — constructed with a preset verdict; returns it from `grade()`. Use to drive pipeline tests deterministically.
- **`RecordingGrader`** — wraps another grader; records every call for later assertion.

---

## 9. HTTP surface

Minimal JSON API under `routes/api.php`, registered with a configurable prefix (default `/modman`).

- `POST /reports` — create a report (used when the Reportable trait method isn't enough, e.g., an anonymous reporter or a cross-service trigger).
- `GET /reports/{id}` — fetch a report with decisions and transitions.
- `POST /reports/{id}/resolve` — human resolution (approve or reject with reason). Gated by a policy.
- `POST /reports/{id}/reopen` — reopen a resolved report.

All endpoints go through standard Laravel auth/authorization middleware. The package does not ship its own gates; consumers define them against the `Report` model. Routes are also disableable via config for consumers who want to register their own.

---

## 10. Testing strategy

### 10.1 Tooling

- **Pest 3** (PHPUnit underneath).
- **Larastan level 9**.
- **Laravel Pint**.
- **Rector** with Laravel + PHP 8.3 sets (dry-run on CI).
- **composer-unused** and **composer-require-checker**.
- **orchestra/testbench** to boot a minimal Laravel in tests.

### 10.2 Layers

- **Unit** — graders, policy, value objects, orchestrator. Fast. No DB.
- **Feature** — full pipeline flows against in-memory SQLite. Asserts state transitions, decisions, events. Each test builds its own world via factories.
- **Conformance** — a reusable Pest test file any custom `Grader` can run against itself. Verifies the contract: idempotence, `supports()` honesty, no exceptions for unsupported content, `Verdict` invariants.
- **Architecture** — Pest Arch tests as a first-class CI gate (see 10.4).

### 10.3 Ephemeral state

- No seeders. Every test builds its own data via factories.
- `ReportFactory` does not require a reportable; it builds a generic `TestReportable` Eloquent model fixture inside the test suite.
- Time-dependent assertions use `Carbon::setTestNow()`.
- In-memory SQLite; runs in parallel.
- `Http::fake()` for all outbound calls; canned response helpers in `tests/Fixtures/Http/`.

### 10.4 Pest architecture tests

Enforced in CI as a dedicated job (`pest --group=arch`):

- `src/Contracts/*` — interfaces only, no concrete classes.
- `src/Graders/*` — implement `Grader`, are `final`, stateless (no mutable instance properties).
- `src/Policy/*` — implement `ModerationPolicy`, are `final`.
- `src/Events/*` — `final readonly`, plain PHP objects (no framework base class), typed properties.
- `src/States/*` and `src/Transitions/*` — live under these namespaces only and extend the appropriate spatie base.
- `src/Models/*` — extend `Illuminate\Database\Eloquent\Model`. Allowed: relations, scopes, casts, accessors/mutators. Disallowed: business logic, HTTP calls, queue dispatches.
- `src/Jobs/*` — implement `ShouldQueue`; `final`.
- `src/Support/*` — no dependencies on `Illuminate\Database` or HTTP clients; pure value objects and helpers.
- Global: no `dd`, `dump`, `var_dump`, `die`, `exit` anywhere in `src/`.
- Global: no direct `new` of Guzzle/HTTP clients in `src/`; HTTP must go through `Http::` so `Http::fake()` works.
- `src/` must not depend on `tests/` or `database/factories/`.
- Strict types declared in every file under `src/`.

### 10.5 CI matrix

GitHub Actions. Matrix: PHP 8.3 and 8.4 × Laravel 11 and 12.

Jobs:

- Pint (format check).
- Larastan level 9.
- Rector dry-run.
- composer-unused, composer-require-checker.
- Pest unit + feature + conformance.
- Pest arch.

All green required before merge.

---

## 11. Documentation

- `README.md` — install, minimum viable example, upgrade notes.
- `docs/` per-topic pages: `pipeline.md`, `graders.md`, `policy.md`, `events.md`, `writing-a-custom-grader.md`, `writing-a-custom-policy.md`.
- `docs/adr/` — ADRs for: spatie state choice, event-only telemetry, single-package topology, headless-only v1.

---

## 12. Deferred / future

- Video moderation (content contract already reserves the slot).
- Filament admin UI companion package (`dynamik-dev/modman-filament`).
- Distributed workflow orchestration (Temporal integration) — only if someone actually needs durable replay.
- Per-report SLA tracking and escalation timers.
- OTel exporter package (`dynamik-dev/modman-otel`) as an optional companion.
- Multi-tenant scoping.

---

## 13. Open questions

None at spec time. Surface any during plan writing.
