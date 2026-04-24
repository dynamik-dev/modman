<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\PerceptualHashGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\Image;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\VerdictKind;

it('rejects when an image matches a known-bad hash', function (): void {
    $hasher = fn (Image $img): string => 'deadbeefdeadbeef';

    $grader = new PerceptualHashGrader(
        knownHashes: ['deadbeefdeadbeef'],
        hasher: $hasher,
    );

    $verdict = $grader->grade(
        ModerationContent::make()->withImages([new Image('https://example.com/a.jpg')]),
        Report::factory()->make(),
    );

    expect($verdict->kind)->toBe(VerdictKind::Reject);
});

it('skips when no hasher is configured', function (): void {
    $grader = new PerceptualHashGrader(knownHashes: [], hasher: null);
    expect($grader->supports(ModerationContent::make()->withImages([new Image('x')])))->toBeFalse();
});
