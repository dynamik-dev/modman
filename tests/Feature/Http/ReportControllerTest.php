<?php

declare(strict_types=1);

use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Tests\Fixtures\TestReportable;

it('returns a report with its decisions', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);

    $this->getJson(route('modman.reports.show', $report))
        ->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.state', 'needs_human');
});

it('resolves a report with an authenticated actor', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);
    $moderator = TestReportable::create(['body' => 'mod']);

    $this->actingAs($moderator)
        ->postJson(route('modman.reports.resolve', $report), [
            'decision' => 'approve',
            'reason' => 'false positive',
        ])
        ->assertOk()
        ->assertJsonPath('data.state', 'resolved_approved');
});

it('rejects resolution without auth', function (): void {
    $report = Report::factory()->create(['state' => 'needs_human']);

    $this->postJson(route('modman.reports.resolve', $report), ['decision' => 'approve'])
        ->assertStatus(401);
});
