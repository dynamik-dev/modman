<?php

declare(strict_types=1);

use Dynamik\Modman\Models\Report;
use Dynamik\Modman\States\NeedsHuman;
use Dynamik\Modman\States\Pending;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\States\Screening;
use Dynamik\Modman\Transitions\ToNeedsHuman;
use Dynamik\Modman\Transitions\ToResolvedApproved;
use Dynamik\Modman\Transitions\ToScreening;
use Illuminate\Support\Facades\DB;

it('defaults new reports to Pending', function (): void {
    $report = Report::factory()->create();
    expect($report->state)->toBeInstanceOf(Pending::class);
});

it('transitions Pending -> Screening -> ResolvedApproved', function (): void {
    $report = Report::factory()->create();
    $report->state->transition(new ToScreening($report));
    expect($report->fresh()->state)->toBeInstanceOf(Screening::class);

    $report = $report->fresh();
    $report->state->transition(new ToResolvedApproved($report));
    expect($report->fresh()->state)->toBeInstanceOf(ResolvedApproved::class);
});

it('logs transitions to moderation_transitions', function (): void {
    $report = Report::factory()->create();
    $report->state->transition(new ToScreening($report));

    $transitions = DB::table('moderation_transitions')->get();
    expect($transitions)->toHaveCount(1);
    expect($transitions[0]->from_state)->toBe('pending');
    expect($transitions[0]->to_state)->toBe('screening');
});

it('reopens a resolved report into NeedsHuman', function (): void {
    $report = Report::factory()->create(['state' => 'resolved_approved']);
    $report->state->transition(new ToNeedsHuman($report));
    expect($report->fresh()->state)->toBeInstanceOf(NeedsHuman::class);
});
