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

// task-17: regex patterns match against raw text — case_sensitive=false does NOT
// imply case-insensitive regex. Callers express that with the `/i` flag.
it('matches regex patterns against raw text and does not auto-lowercase', function (): void {
    $caseInsensitive = new DenylistGrader(regex: ['/badword/i'], caseSensitive: false);
    $caseSensitiveOnly = new DenylistGrader(regex: ['/badword/'], caseSensitive: false);

    $content = ModerationContent::make()->withText('contains BadWord here');
    $report = Report::factory()->make();

    expect($caseInsensitive->grade($content, $report)->kind)->toBe(VerdictKind::Reject);
    // Without /i the pattern does not match even though words would normalize.
    expect($caseSensitiveOnly->grade($content, $report)->kind)->toBe(VerdictKind::Approve);
});

// Case-sensitive mode still transliterates both haystack and needle. Without
// matching normalization the haystack would collapse "café" → "cafe" while the
// configured word stayed "café", so the literal would never match its own text.
it('matches a transliterated word in case-sensitive mode', function (): void {
    $grader = new DenylistGrader(words: ['café'], caseSensitive: true);
    $verdict = $grader->grade(
        ModerationContent::make()->withText('order a café please'),
        Report::factory()->make(),
    );

    expect($verdict->kind)->toBe(VerdictKind::Reject);
    expect($verdict->evidence['matches'])->toContain('café');
});

it('preserves case in case-sensitive mode', function (): void {
    $grader = new DenylistGrader(words: ['Hate'], caseSensitive: true);

    $upper = $grader->grade(
        ModerationContent::make()->withText('I Hate this'),
        Report::factory()->make(),
    );
    $lower = $grader->grade(
        ModerationContent::make()->withText('i hate this'),
        Report::factory()->make(),
    );

    expect($upper->kind)->toBe(VerdictKind::Reject);
    expect($lower->kind)->toBe(VerdictKind::Approve);
});
