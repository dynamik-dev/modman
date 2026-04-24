<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\Testing\FakeGrader;
use Dynamik\Modman\Jobs\RunModerationPipeline;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Pipeline\Orchestrator;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\Queue;

it('runs the full happy path: create -> screen -> approve', function (): void {
    Queue::fake();
    $fake = new FakeGrader('denylist', new Verdict(VerdictKind::Approve, 0.0, 'clean'));
    config()->set('modman.pipeline', ['denylist' => FakeGrader::class]);
    app()->instance(FakeGrader::class, $fake);

    $reportable = TestReportable::create(['body' => 'hi']);
    $report = $reportable->report(null, 'spam');

    Queue::assertPushed(RunModerationPipeline::class);

    // Execute the pushed job synchronously.
    (new RunModerationPipeline($report->id))->handle(app(Orchestrator::class));

    expect(Report::find($report->id)->state)->toBeInstanceOf(ResolvedApproved::class);
});
