<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\HeuristicGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\VerdictKind;

it('flags all-caps text as inconclusive', function (): void {
    $verdict = (new HeuristicGrader)->grade(
        ModerationContent::make()->withText('THIS IS ALL CAPS SHOUTING AT YOU'),
        Report::factory()->make(),
    );
    expect($verdict->kind)->toBe(VerdictKind::Inconclusive);
    expect($verdict->severity)->toBeGreaterThan(0.3);
});

it('flags high link density', function (): void {
    $verdict = (new HeuristicGrader)->grade(
        ModerationContent::make()->withText('a https://a.com b https://b.com c https://c.com'),
        Report::factory()->make(),
    );
    expect($verdict->kind)->toBe(VerdictKind::Inconclusive);
});

it('approves normal prose', function (): void {
    $verdict = (new HeuristicGrader)->grade(
        ModerationContent::make()->withText('This is a perfectly ordinary sentence with nothing weird.'),
        Report::factory()->make(),
    );
    expect($verdict->kind)->toBe(VerdictKind::Approve);
});
