<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\DenylistGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\VerdictKind;

it('has a stable key', function (): void {
    $grader = new DenylistGrader(words: []);
    expect($grader->key())->toBe('denylist');
});

it('supports text content', function (): void {
    $grader = new DenylistGrader(words: ['badword']);
    expect($grader->supports(ModerationContent::make()->withText('hi')))->toBeTrue();
});

it('does not support empty content', function (): void {
    $grader = new DenylistGrader(words: ['badword']);
    expect($grader->supports(ModerationContent::make()))->toBeFalse();
});

it('rejects text containing a denied word', function (): void {
    $grader = new DenylistGrader(words: ['badword']);
    $verdict = $grader->grade(
        ModerationContent::make()->withText('this is BadWord with emphasis'),
        Report::factory()->make(),
    );
    expect($verdict->kind)->toBe(VerdictKind::Reject);
    expect($verdict->severity)->toBeGreaterThanOrEqual(0.9);
    expect($verdict->evidence['matches'])->toContain('badword');
});

it('approves clean text', function (): void {
    $grader = new DenylistGrader(words: ['badword']);
    $verdict = $grader->grade(
        ModerationContent::make()->withText('this is a perfectly fine sentence'),
        Report::factory()->make(),
    );
    expect($verdict->kind)->toBe(VerdictKind::Approve);
    expect($verdict->severity)->toBe(0.0);
});

it('matches regex patterns', function (): void {
    $grader = new DenylistGrader(words: [], regex: ['/\\bhate\\s+\\w+/i']);
    $verdict = $grader->grade(
        ModerationContent::make()->withText('I hate bananas'),
        Report::factory()->make(),
    );
    expect($verdict->kind)->toBe(VerdictKind::Reject);
});

it('normalizes unicode confusables', function (): void {
    $grader = new DenylistGrader(words: ['hate']);
    $verdict = $grader->grade(
        ModerationContent::make()->withText("h\u{0430}te"), // Cyrillic a
        Report::factory()->make(),
    );
    expect($verdict->kind)->toBe(VerdictKind::Reject);
});
