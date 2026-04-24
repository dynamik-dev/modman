<?php

declare(strict_types=1);

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Graders\DenylistGrader;
use Dynamik\Modman\Graders\HeuristicGrader;
use Dynamik\Modman\Graders\Testing\FakeGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\Image;
use Dynamik\Modman\Support\ModerationContent;

/**
 * Reusable conformance suite for any Grader implementation.
 *
 * Consumers run it via:
 *   it('conforms', fn () => assertGraderConforms(fn () => new MyCustomGrader(...)));
 */
function assertGraderConforms(Closure $factory): void
{
    /** @var Grader $g1 */
    $g1 = $factory();
    /** @var Grader $g2 */
    $g2 = $factory();

    // key() is stable across instances.
    expect($g1->key())->toBe($g2->key());
    expect($g1->key())->not->toBe('');

    // supports() is honest: a totally empty content never throws, and either returns true or false.
    $empty = ModerationContent::make();
    $emptySupports = $g1->supports($empty);
    expect($emptySupports)->toBeBool();

    // If supports() returned false for empty, calling grade() on it is undefined —
    // we only require that unsupported content does not crash supports().
    $textOnly = ModerationContent::make()->withText('sample text');
    $imageOnly = ModerationContent::make()->withImages([new Image('https://example.com/x.jpg')]);

    foreach ([$textOnly, $imageOnly] as $content) {
        $supports = $g1->supports($content);
        expect($supports)->toBeBool();

        if ($supports) {
            $report = Report::factory()->make();
            $verdict = $g1->grade($content, $report);
            expect($verdict->severity)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
        }
    }
}

// Run the suite against each shipped grader.
it('DenylistGrader conforms', function (): void {
    assertGraderConforms(fn () => new DenylistGrader(words: ['badword']));
});

it('FakeGrader conforms', function (): void {
    assertGraderConforms(fn () => new FakeGrader);
});

it('HeuristicGrader conforms', function (): void {
    assertGraderConforms(fn () => new HeuristicGrader);
});
