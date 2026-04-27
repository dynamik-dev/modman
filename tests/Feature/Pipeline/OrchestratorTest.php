<?php

declare(strict_types=1);

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Contracts\ModerationPolicy;
use Dynamik\Modman\Events\GraderRan;
use Dynamik\Modman\Events\ReportAwaitingHuman;
use Dynamik\Modman\Events\ReportResolved;
use Dynamik\Modman\Graders\Testing\FakeGrader;
use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Pipeline\Orchestrator;
use Dynamik\Modman\States\NeedsHuman;
use Dynamik\Modman\States\NeedsLlm;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\States\ResolvedRejected;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\PolicyAction;
use Dynamik\Modman\Support\PolicyActions\EscalateTo;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

final class DenylistFixedGrader implements Grader
{
    public function __construct(public Verdict $verdict, public bool $supports = true) {}

    public function key(): string
    {
        return 'denylist';
    }

    public function supports(ModerationContent $content): bool
    {
        return $this->supports;
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        return $this->verdict;
    }
}

final class LlmFixedGrader implements Grader
{
    public function __construct(public Verdict $verdict, public bool $supports = true) {}

    public function key(): string
    {
        return 'llm';
    }

    public function supports(ModerationContent $content): bool
    {
        return $this->supports;
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        return $this->verdict;
    }
}

final class OpenAiModerationFixedGrader implements Grader
{
    public function __construct(public Verdict $verdict, public bool $supports = true) {}

    public function key(): string
    {
        return 'openai_moderation';
    }

    public function supports(ModerationContent $content): bool
    {
        return $this->supports;
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        return $this->verdict;
    }
}

it('runs the first grader and resolves when policy returns Approve', function (): void {
    Event::fake([GraderRan::class, ReportResolved::class]);

    $grader = new FakeGrader('denylist', new Verdict(VerdictKind::Approve, 0.0, 'clean'));
    config()->set('modman.pipeline', ['denylist' => $grader::class]);
    app()->instance($grader::class, $grader);

    $reportable = TestReportable::create(['body' => 'hi']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    $fresh = $report->fresh();
    expect($fresh->state)->toBeInstanceOf(ResolvedApproved::class);
    expect($fresh->decisions()->count())->toBe(1);

    $decision = $fresh->decisions()->first();
    expect($decision->grader)->toBe('denylist');
    expect($decision->tier)->toBe('denylist');

    Event::assertDispatched(GraderRan::class);
    Event::assertDispatched(ReportResolved::class);
});

it('routes to human when policy returns RouteToHuman', function (): void {
    Event::fake([ReportAwaitingHuman::class]);

    $grader = new FakeGrader('denylist', new Verdict(VerdictKind::Inconclusive, 0.5, 'unsure'));
    config()->set('modman.pipeline', ['denylist' => $grader::class]);
    app()->instance($grader::class, $grader);

    $reportable = TestReportable::create(['body' => 'hmm']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    $fresh = $report->fresh();
    expect($fresh->state->getValue())->toBe('needs_human');
    Event::assertDispatched(ReportAwaitingHuman::class);
});

it('records an error verdict when a grader throws', function (): void {
    $grader = new class implements Grader
    {
        public function key(): string
        {
            return 'denylist';
        }

        public function supports(ModerationContent $c): bool
        {
            return true;
        }

        public function grade(ModerationContent $c, Report $r): Verdict
        {
            throw new RuntimeException('boom');
        }
    };

    config()->set('modman.pipeline', ['denylist' => $grader::class]);
    app()->instance($grader::class, $grader);

    $reportable = TestReportable::create(['body' => 'hmm']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    $fresh = $report->fresh();
    $decision = $fresh->decisions()->first();
    expect($decision->verdict)->toBe(VerdictKind::Error);
    expect($decision->severity)->toBeNull();
    expect($decision->evidence['exception'])->toBe('RuntimeException');
    expect($fresh->state->getValue())->toBe('needs_human');
});

it('advances to the next grader when policy escalates in a multi-tier pre-LLM pipeline', function (): void {
    $denylist = new class implements Grader
    {
        public function key(): string
        {
            return 'denylist';
        }

        public function supports(ModerationContent $c): bool
        {
            return true;
        }

        public function grade(ModerationContent $c, Report $r): Verdict
        {
            return new Verdict(VerdictKind::Inconclusive, 0.4, 'maybe');
        }
    };

    $heuristic = new class implements Grader
    {
        public function key(): string
        {
            return 'heuristic';
        }

        public function supports(ModerationContent $c): bool
        {
            return true;
        }

        public function grade(ModerationContent $c, Report $r): Verdict
        {
            return new Verdict(VerdictKind::Approve, 0.1, 'looks ok');
        }
    };

    config()->set('modman.pipeline', [
        'denylist' => $denylist::class,
        'heuristic' => $heuristic::class,
    ]);
    app()->instance($denylist::class, $denylist);
    app()->instance($heuristic::class, $heuristic);

    $reportable = TestReportable::create(['body' => 'maybe questionable']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    $fresh = $report->fresh();
    expect($fresh->decisions()->count())->toBe(2);

    $graders = $fresh->decisions()->orderBy('id')->pluck('grader')->all();
    expect($graders)->toContain('denylist');
    expect($graders)->toContain('heuristic');

    expect($fresh->state)->toBeInstanceOf(ResolvedApproved::class);
});

it('records a skipped decision and continues when a grader does not support the content', function (): void {
    $denylist = new class implements Grader
    {
        public function key(): string
        {
            return 'denylist';
        }

        public function supports(ModerationContent $c): bool
        {
            return false;
        }

        public function grade(ModerationContent $c, Report $r): Verdict
        {
            throw new RuntimeException('should not be called');
        }
    };

    $heuristic = new class implements Grader
    {
        public function key(): string
        {
            return 'heuristic';
        }

        public function supports(ModerationContent $c): bool
        {
            return true;
        }

        public function grade(ModerationContent $c, Report $r): Verdict
        {
            return new Verdict(VerdictKind::Approve, 0.05, 'all good');
        }
    };

    config()->set('modman.pipeline', [
        'denylist' => $denylist::class,
        'heuristic' => $heuristic::class,
    ]);
    app()->instance($denylist::class, $denylist);
    app()->instance($heuristic::class, $heuristic);

    $reportable = TestReportable::create(['body' => 'anything']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    $fresh = $report->fresh();
    expect($fresh->decisions()->count())->toBe(2);

    $decisions = $fresh->decisions()->orderBy('id')->get();
    expect($decisions[0]->grader)->toBe('denylist');
    expect($decisions[0]->verdict)->toBe(VerdictKind::Skipped);
    expect($decisions[0]->evidence['skipped'])->toBeTrue();

    expect($decisions[1]->grader)->toBe('heuristic');
    expect($decisions[1]->verdict)->toBe(VerdictKind::Approve);

    expect($fresh->state)->toBeInstanceOf(ResolvedApproved::class);
});

// task-19 + task-1: Inconclusive screening escalates to needs_llm, llm runs, llm rejects -> ResolvedRejected.
it('passes through needs_llm when screening is inconclusive and writes a decision per grader', function (): void {
    Queue::fake();

    $denylist = new DenylistFixedGrader(new Verdict(VerdictKind::Inconclusive, 0.5, 'maybe'));
    $llm = new LlmFixedGrader(new Verdict(VerdictKind::Inconclusive, 0.5, 'still unsure'));

    config()->set('modman.pipeline', [
        'denylist' => $denylist::class,
        'llm' => $llm::class,
    ]);
    app()->instance($denylist::class, $denylist);
    app()->instance($llm::class, $llm);

    $reportable = TestReportable::create(['body' => 'borderline']);
    $report = $reportable->report(null, 'spam');

    // First tick: denylist -> escalate to needs_llm.
    app(Orchestrator::class)->runNext($report->fresh());
    expect($report->fresh()->state)->toBeInstanceOf(NeedsLlm::class);

    // Second tick: llm runs in needs_llm.
    app(Orchestrator::class)->runNext($report->fresh());

    $fresh = $report->fresh();
    expect($fresh->decisions()->count())->toBe(2);
    expect($fresh->state)->toBeInstanceOf(NeedsHuman::class);

    $graders = $fresh->decisions()->orderBy('id')->pluck('grader')->all();
    expect($graders)->toBe(['denylist', 'llm']);
});

it('resolves rejected when LLM returns Reject in needs_llm', function (): void {
    Queue::fake();

    $denylist = new DenylistFixedGrader(new Verdict(VerdictKind::Inconclusive, 0.5, 'maybe'));
    $llm = new LlmFixedGrader(new Verdict(VerdictKind::Reject, 0.95, 'definitely bad'));

    config()->set('modman.pipeline', [
        'denylist' => $denylist::class,
        'llm' => $llm::class,
    ]);
    app()->instance($denylist::class, $denylist);
    app()->instance($llm::class, $llm);

    $reportable = TestReportable::create(['body' => 'spam-ish']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());
    app(Orchestrator::class)->runNext($report->fresh());

    expect($report->fresh()->state)->toBeInstanceOf(ResolvedRejected::class);
});

// task-1 regression: pipeline with graders after 'llm' must not loop.
it('does not loop when graders are configured after llm and llm has produced a decision', function (): void {
    Queue::fake();

    $denylist = new DenylistFixedGrader(new Verdict(VerdictKind::Inconclusive, 0.5, 'maybe'));
    $llm = new LlmFixedGrader(new Verdict(VerdictKind::Inconclusive, 0.5, 'still unsure'));
    $extra = new OpenAiModerationFixedGrader(new Verdict(VerdictKind::Inconclusive, 0.5, 'still unsure'));

    config()->set('modman.pipeline', [
        'denylist' => $denylist::class,
        'llm' => $llm::class,
        'openai_moderation' => $extra::class,
    ]);
    app()->instance($denylist::class, $denylist);
    app()->instance($llm::class, $llm);
    app()->instance($extra::class, $extra);

    $reportable = TestReportable::create(['body' => 'borderline']);
    $report = $reportable->report(null, 'spam');

    // Drive the pipeline up to a bounded number of ticks. Without the fix this
    // would re-run llm forever; with the fix llm runs once then the next tick
    // lands in needs_human.
    for ($i = 0; $i < 6; $i++) {
        $current = $report->fresh();
        if ($current->state->isTerminal() || $current->state instanceof NeedsHuman) {
            break;
        }
        app(Orchestrator::class)->runNext($current);
    }

    $fresh = $report->fresh();
    expect($fresh->state)->toBeInstanceOf(NeedsHuman::class);

    $llmDecisions = $fresh->decisions()->where('grader', 'llm')->count();
    expect($llmDecisions)->toBe(1);
});

// task-4: re-running on a NeedsHuman report does not fire ReportAwaitingHuman again.
it('does not re-fire ReportAwaitingHuman when the report is already needs_human', function (): void {
    Event::fake([ReportAwaitingHuman::class]);

    $grader = new FakeGrader('denylist', new Verdict(VerdictKind::Inconclusive, 0.5, 'unsure'));
    config()->set('modman.pipeline', ['denylist' => $grader::class]);
    app()->instance($grader::class, $grader);

    $reportable = TestReportable::create(['body' => 'hmm']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());
    app(Orchestrator::class)->runNext($report->fresh());

    Event::assertDispatchedTimes(ReportAwaitingHuman::class, 1);
});

// task-7 + task-8: replays do not produce duplicate automated decision rows.
it('writes a single decision per grader when the orchestrator runs twice for the same step', function (): void {
    $grader = new FakeGrader('denylist', new Verdict(VerdictKind::Inconclusive, 0.5, 'maybe'));
    config()->set('modman.pipeline', ['denylist' => $grader::class]);
    app()->instance($grader::class, $grader);

    $reportable = TestReportable::create(['body' => 'hmm']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    // After first run, report is in needs_human (single grader, inconclusive).
    // Even if the orchestrator is invoked again, the firstOrCreate dedup +
    // NeedsHuman early-return guard mean no second decision row is written.
    app(Orchestrator::class)->runNext($report->fresh());

    $denylistCount = Decision::query()
        ->where('report_id', $report->id)
        ->where('grader', 'denylist')
        ->count();
    expect($denylistCount)->toBe(1);
});

// task-5: EscalateTo with a key not in config('modman.pipeline') fails loudly.
it('throws when EscalateTo targets a grader that is not in the configured pipeline', function (): void {
    Queue::fake();

    $stallPolicy = new class implements ModerationPolicy
    {
        public function decide(Report $report, Decision $latest): PolicyAction
        {
            return new EscalateTo('not_in_pipeline');
        }
    };
    app()->instance(ModerationPolicy::class, $stallPolicy);

    $grader = new FakeGrader('denylist', new Verdict(VerdictKind::Inconclusive, 0.5, 'unsure'));
    config()->set('modman.pipeline', ['denylist' => $grader::class]);
    app()->instance($grader::class, $grader);

    $reportable = TestReportable::create(['body' => 'hmm']);
    $reportable->report(null, 'spam');

    $report = Report::query()->latest()->first();

    expect(fn () => app(Orchestrator::class)->runNext($report))
        ->toThrow(RuntimeException::class, 'EscalateTo target');
});

// task-35: a grader whose key() drifts from the configured key must fail loudly.
it('throws when a grader\'s key() does not match the configured pipeline key', function (): void {
    Queue::fake();

    $grader = new FakeGrader('actual_key', new Verdict(VerdictKind::Approve, 0.0, 'fine'));
    config()->set('modman.pipeline', ['configured_key' => $grader::class]);
    app()->instance($grader::class, $grader);

    $reportable = TestReportable::create(['body' => 'hmm']);
    $reportable->report(null, 'spam');

    $report = Report::query()->latest()->first();

    expect(fn () => app(Orchestrator::class)->runNext($report))
        ->toThrow(RuntimeException::class, 'Grader key mismatch');
});
