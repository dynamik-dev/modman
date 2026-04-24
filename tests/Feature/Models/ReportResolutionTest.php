<?php

declare(strict_types=1);

use Dynamik\Modman\Events\ReportReopened;
use Dynamik\Modman\Events\ReportResolved;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\States\NeedsHuman;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\States\ResolvedRejected;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\Event;

it('resolveApprove writes a human decision and transitions to ResolvedApproved', function (): void {
    Event::fake([ReportResolved::class]);
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'moderator']); // stand-in actor

    $report->resolveApprove($moderator, 'false positive');

    $fresh = $report->fresh();
    expect($fresh->state)->toBeInstanceOf(ResolvedApproved::class);
    $decision = $fresh->decisions()->first();
    expect($decision->tier)->toBe('human');
    expect($decision->verdict)->toBe('approve');
    expect($decision->reason)->toBe('false positive');
    Event::assertDispatched(ReportResolved::class);
});

it('resolveReject sets ResolvedRejected', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'moderator']);

    $report->resolveReject($moderator, 'violates policy');

    expect($report->fresh()->state)->toBeInstanceOf(ResolvedRejected::class);
});

it('reopen moves a resolved report back to NeedsHuman', function (): void {
    Event::fake([ReportReopened::class]);
    $report = Report::factory()->create(['state' => 'resolved_approved']);
    $moderator = TestReportable::create(['body' => 'moderator']);

    $report->reopen($moderator, 'appeal');

    expect($report->fresh()->state)->toBeInstanceOf(NeedsHuman::class);
    Event::assertDispatched(ReportReopened::class);
});
