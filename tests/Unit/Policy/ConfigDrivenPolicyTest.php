<?php

declare(strict_types=1);

use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Policy\ConfigDrivenPolicy;
use Dynamik\Modman\Support\PolicyActions\Approve;
use Dynamik\Modman\Support\PolicyActions\EscalateTo;
use Dynamik\Modman\Support\PolicyActions\Reject;
use Dynamik\Modman\Support\PolicyActions\RouteToHuman;

function makePolicy(array $pipeline = ['denylist', 'llm'], float $rejectAt = 0.9, float $approveBelow = 0.2): ConfigDrivenPolicy
{
    return new ConfigDrivenPolicy(
        pipeline: $pipeline,
        autoRejectAt: $rejectAt,
        autoApproveBelow: $approveBelow,
    );
}

it('rejects when severity >= auto_reject_at', function (): void {
    $report = Report::factory()->make();
    $decision = Decision::factory()->make(['grader' => 'denylist', 'verdict' => 'reject', 'severity' => 0.95]);

    expect(makePolicy()->decide($report, $decision))->toBeInstanceOf(Reject::class);
});

it('approves when severity < auto_approve_below and verdict is approve', function (): void {
    $report = Report::factory()->make();
    $decision = Decision::factory()->make(['grader' => 'denylist', 'verdict' => 'approve', 'severity' => 0.05]);

    expect(makePolicy()->decide($report, $decision))->toBeInstanceOf(Approve::class);
});

it('escalates to the next grader when severity is in the uncertain band', function (): void {
    $report = Report::factory()->make();
    $decision = Decision::factory()->make(['grader' => 'denylist', 'verdict' => 'inconclusive', 'severity' => 0.5]);

    $action = makePolicy()->decide($report, $decision);
    expect($action)->toBeInstanceOf(EscalateTo::class);
    /** @var EscalateTo $action */
    expect($action->graderKey)->toBe('llm');
});

it('routes to human when there is no next grader', function (): void {
    $report = Report::factory()->make();
    $decision = Decision::factory()->make(['grader' => 'llm', 'verdict' => 'inconclusive', 'severity' => 0.5]);

    expect(makePolicy()->decide($report, $decision))->toBeInstanceOf(RouteToHuman::class);
});

it('routes to human on grader error at the final tier', function (): void {
    $report = Report::factory()->make();
    $decision = Decision::factory()->make(['grader' => 'llm', 'verdict' => 'error', 'severity' => null]);

    expect(makePolicy()->decide($report, $decision))->toBeInstanceOf(RouteToHuman::class);
});

it('escalates on grader error when a next tier exists', function (): void {
    $report = Report::factory()->make();
    $decision = Decision::factory()->make(['grader' => 'denylist', 'verdict' => 'error', 'severity' => null]);

    $action = makePolicy()->decide($report, $decision);
    expect($action)->toBeInstanceOf(EscalateTo::class);
});
