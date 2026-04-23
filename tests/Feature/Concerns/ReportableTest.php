<?php

declare(strict_types=1);

use Dynamik\Modman\Events\ReportCreated;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\Event;

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
