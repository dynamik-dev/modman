<?php

declare(strict_types=1);

use Dynamik\Modman\Events\ReportCreated;
use Dynamik\Modman\Jobs\RunModerationPipeline;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('creates a report linked to the host model', function (): void {
    Event::fake([ReportCreated::class]);

    $reportable = TestReportable::create(['body' => 'hello world']);
    $report = $reportable->report(reporter: null, reason: 'spam');

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->reportable_type)->toBe(TestReportable::class);
    expect($report->reportable_id)->toBe($reportable->id);
    expect($report->reason)->toBe('spam');
    expect((string) $report->state)->toBe('pending');

    Event::assertDispatched(ReportCreated::class);
});

it('exposes a reports() MorphMany on the host', function (): void {
    $reportable = TestReportable::create(['body' => 'x']);
    $reportable->report(null, 'spam');
    $reportable->report(null, 'abuse');

    expect($reportable->reports()->count())->toBe(2);
});

// ReportCreated and the RunModerationPipeline job dispatch must defer to
// commit. If a host wraps $reportable->report(...) in their own transaction
// that later rolls back, listeners must NOT see a phantom report and the
// queue must NOT receive a job pointing at a row that never persisted.
it('does not dispatch ReportCreated or enqueue the pipeline job when an outer transaction rolls back', function (): void {
    Event::fake([ReportCreated::class]);
    Queue::fake();

    $reportable = TestReportable::create(['body' => 'hello']);

    try {
        DB::transaction(function () use ($reportable): void {
            $reportable->report(null, 'spam');
            throw new RuntimeException('outer rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(ReportCreated::class);
    Queue::assertNothingPushed();
    expect(Report::query()->count())->toBe(0);
});

it('dispatches ReportCreated and enqueues the pipeline job when an outer transaction commits', function (): void {
    Event::fake([ReportCreated::class]);
    Queue::fake();

    $reportable = TestReportable::create(['body' => 'hello']);

    DB::transaction(function () use ($reportable): void {
        $reportable->report(null, 'spam');
    });

    Event::assertDispatched(ReportCreated::class);
    Queue::assertPushed(RunModerationPipeline::class);
});
