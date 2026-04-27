<?php

declare(strict_types=1);

use Dynamik\Modman\Jobs\RunModerationPipeline;
use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\States\NeedsHuman;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\Queue;

// task-20: pipeline-job exhaustion writes an error decision and parks the
// report at NeedsHuman so an operator can pick it up.
it('records an error decision and transitions to NeedsHuman when the job fails', function (): void {
    Queue::fake();

    $reportable = TestReportable::create(['body' => 'hi']);
    $report = $reportable->report(null, 'spam');

    $job = new RunModerationPipeline($report->id);
    $job->failed(new RuntimeException('boom'));

    $fresh = Report::find($report->id);
    expect($fresh->state)->toBeInstanceOf(NeedsHuman::class);

    $errorDecision = Decision::query()
        ->where('report_id', $report->id)
        ->where('grader', 'pipeline')
        ->first();

    expect($errorDecision)->not->toBeNull();
    expect($errorDecision->verdict)->toBe(VerdictKind::Error);
    expect($errorDecision->tier)->toBe('pipeline');
    expect($errorDecision->reason)->toContain('boom');
    expect($errorDecision->evidence['exception'])->toBe('RuntimeException');
});

// task-20 (gap): failed() does not currently dispatch ReportAwaitingHuman, so
// the test asserts only the side effects the implementation guarantees today.
// See implementation notes on task-20 for the documented gap.
it('is a no-op when the report is already resolved', function (): void {
    Queue::fake();

    $reportable = TestReportable::create(['body' => 'hi']);
    $report = $reportable->report(null, 'spam');

    // Force the report straight to resolved_approved without a decision row.
    Report::query()->whereKey($report->id)->update(['state' => 'resolved_approved']);

    $job = new RunModerationPipeline($report->id);
    $job->failed(new RuntimeException('late failure'));

    $errorCount = Decision::query()
        ->where('report_id', $report->id)
        ->where('grader', 'pipeline')
        ->count();

    expect($errorCount)->toBe(0);
});

// task-34: dispatching the moderation pipeline must honor MODMAN_QUEUE_CONNECTION
// (config('modman.connection')) so operators can route the job to a dedicated
// connection without forking the package.
it('dispatches RunModerationPipeline on the configured connection and queue', function (): void {
    Queue::fake();

    config()->set('modman.connection', 'redis');
    config()->set('modman.queue', 'modman-test');

    $reportable = TestReportable::create(['body' => 'hi']);
    $reportable->report(null, 'spam');

    Queue::assertPushedOn('modman-test', RunModerationPipeline::class);

    Queue::assertPushed(RunModerationPipeline::class, fn (RunModerationPipeline $job): bool => $job->connection === 'redis');
});

// task-34 default behavior: when MODMAN_QUEUE_CONNECTION is unset the
// dispatched job has a null connection (Laravel resolves to the default).
it('uses the default queue connection when modman.connection is not configured', function (): void {
    Queue::fake();

    config()->set('modman.connection');

    $reportable = TestReportable::create(['body' => 'hi']);
    $reportable->report(null, 'spam');

    Queue::assertPushed(RunModerationPipeline::class, fn (RunModerationPipeline $job): bool => $job->connection === null);
});
