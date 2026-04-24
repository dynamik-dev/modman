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

function wirePipeline(array $graders): void
{
    config()->set('modman.pipeline', array_map(fn ($g) => get_class($g), $graders));
    foreach ($graders as $grader) {
        app()->instance(get_class($grader), $grader);
    }
}

it('runs the first grader and resolves when policy returns Approve', function (): void {
    Event::fake([GraderRan::class, ReportResolved::class]);

    $grader = new FakeGrader('denylist', new Verdict(VerdictKind::Approve, 0.0, 'clean'));
    config()->set('modman.pipeline', ['denylist' => get_class($grader)]);
    app()->instance(get_class($grader), $grader);

    $reportable = TestReportable::create(['body' => 'hi']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    $fresh = $report->fresh();
    expect($fresh->state)->toBeInstanceOf(ResolvedApproved::class);
    expect($fresh->decisions()->count())->toBe(1);

    Event::assertDispatched(GraderRan::class);
    Event::assertDispatched(ReportResolved::class);
});

it('routes to human when policy returns RouteToHuman', function (): void {
    Event::fake([ReportAwaitingHuman::class]);

    $grader = new FakeGrader('denylist', new Verdict(VerdictKind::Inconclusive, 0.5, 'unsure'));
    config()->set('modman.pipeline', ['denylist' => get_class($grader)]);
    app()->instance(get_class($grader), $grader);

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

    config()->set('modman.pipeline', ['denylist' => get_class($grader)]);
    app()->instance(get_class($grader), $grader);

    $reportable = TestReportable::create(['body' => 'hmm']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    $decision = $report->fresh()->decisions()->first();
    expect($decision->verdict)->toBe('error');
    expect($decision->evidence['exception'])->toBe('RuntimeException');
});
