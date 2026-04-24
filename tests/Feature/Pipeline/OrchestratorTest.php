<?php

declare(strict_types=1);

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Events\GraderRan;
use Dynamik\Modman\Events\ReportAwaitingHuman;
use Dynamik\Modman\Events\ReportResolved;
use Dynamik\Modman\Graders\Testing\FakeGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Pipeline\Orchestrator;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\Event;

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
    expect($decision->verdict)->toBe('error');
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

    $graders = $fresh->decisions()->orderBy('created_at')->pluck('grader')->all();
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

    $decisions = $fresh->decisions()->orderBy('created_at')->get();
    expect($decisions[0]->grader)->toBe('denylist');
    expect($decisions[0]->verdict)->toBe('skipped');
    expect($decisions[0]->evidence['skipped'])->toBeTrue();

    expect($decisions[1]->grader)->toBe('heuristic');
    expect($decisions[1]->verdict)->toBe('approve');

    expect($fresh->state)->toBeInstanceOf(ResolvedApproved::class);
});
