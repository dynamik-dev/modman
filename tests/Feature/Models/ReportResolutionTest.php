<?php

declare(strict_types=1);

use Dynamik\Modman\Events\ReportReopened;
use Dynamik\Modman\Events\ReportResolved;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\States\NeedsHuman;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\States\ResolvedRejected;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

it('resolveApprove writes a human decision and transitions to ResolvedApproved', function (): void {
    Event::fake([ReportResolved::class]);
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'moderator']); // stand-in actor

    $report->resolveApprove($moderator, 'false positive');

    $fresh = $report->fresh();
    expect($fresh->state)->toBeInstanceOf(ResolvedApproved::class);
    expect($fresh->resolved_at)->not->toBeNull();

    $decision = $fresh->decisions()->first();
    expect($decision->tier)->toBe('human');
    expect($decision->verdict)->toBe(VerdictKind::Approve);
    expect($decision->reason)->toBe('false positive');
    expect($decision->actor_type)->toBe($moderator->getMorphClass());
    expect($decision->actor_id)->toBe((string) $moderator->getKey());

    Event::assertDispatched(
        ReportResolved::class,
        fn ($e): bool => $e->outcome === VerdictKind::Approve
    );
});

it('resolveReject sets ResolvedRejected and writes a human reject decision', function (): void {
    Event::fake([ReportResolved::class]);
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'moderator']);

    $report->resolveReject($moderator, 'violates policy');

    $fresh = $report->fresh();
    expect($fresh->state)->toBeInstanceOf(ResolvedRejected::class);
    expect($fresh->resolved_at)->not->toBeNull();

    $decision = $fresh->decisions()->first();
    expect($decision)->not->toBeNull();
    expect($decision->tier)->toBe('human');
    expect($decision->verdict)->toBe(VerdictKind::Reject);
    expect($decision->reason)->toBe('violates policy');
    expect($decision->actor_type)->toBe($moderator->getMorphClass());
    expect($decision->actor_id)->toBe((string) $moderator->getKey());

    Event::assertDispatched(
        ReportResolved::class,
        fn ($e): bool => $e->outcome === VerdictKind::Reject
    );
});

it('reopen moves a resolved report back to NeedsHuman and clears resolved_at', function (): void {
    Event::fake([ReportReopened::class]);
    $report = Report::factory()->create([
        'state' => 'resolved_approved',
        'resolved_at' => now(),
    ]);
    $moderator = TestReportable::create(['body' => 'moderator']);

    $report->reopen($moderator, 'appeal');

    $fresh = $report->fresh();
    expect($fresh->state)->toBeInstanceOf(NeedsHuman::class);
    expect($fresh->resolved_at)->toBeNull();
    expect($fresh->decisions()->count())->toBe(0);

    Event::assertDispatched(ReportReopened::class);
});

it('resolveApprove on a pending report throws', function (): void {
    $report = Report::factory()->create(['state' => 'pending']);
    $moderator = TestReportable::create(['body' => 'moderator']);

    expect(fn () => $report->resolveApprove($moderator, 'x'))
        ->toThrow(CouldNotPerformTransition::class);
});

it('resolveApprove records an audit row in moderation_transitions with actor and reason', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'moderator']);

    $report->resolveApprove($moderator, 'false positive');

    $row = DB::table('moderation_transitions')
        ->where('report_id', $report->id)
        ->orderByDesc('created_at')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->to_state)->toBe('resolved_approved');
    expect($row->actor_type)->toBe($moderator->getMorphClass());
    expect($row->actor_id)->toBe((string) $moderator->getKey());
    expect($row->reason)->toBe('false positive');
});

it('serializes concurrent resolveApprove calls so only one decision and one event are produced', function (): void {
    Event::fake([ReportResolved::class]);
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'moderator']);

    $report->resolveApprove($moderator, 'first');

    $stale = Report::query()->find($report->getKey());
    expect($stale->state)->toBeInstanceOf(ResolvedApproved::class);

    expect(fn () => $stale->resolveApprove($moderator, 'second'))
        ->toThrow(CouldNotPerformTransition::class);

    expect($report->fresh()->decisions()->count())->toBe(1);
    Event::assertDispatchedTimes(ReportResolved::class, 1);
});
