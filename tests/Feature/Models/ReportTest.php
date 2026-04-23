<?php

declare(strict_types=1);

use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;

it('creates a report row via the factory', function (): void {
    $report = Report::factory()->create();
    expect($report->exists)->toBeTrue();
    expect($report->state)->toBe('pending');
});

it('creates decisions linked to a report', function (): void {
    $report = Report::factory()->create();
    Decision::factory()->count(2)->for($report)->create();

    expect($report->decisions()->count())->toBe(2);
    expect($report->decisions()->first()->grader)->toBe('denylist');
});
