<?php

declare(strict_types=1);

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Graders\DenylistGrader;
use Dynamik\Modman\Graders\HeuristicGrader;
use Dynamik\Modman\Graders\LlmGrader;
use Dynamik\Modman\Graders\OpenAiModerationGrader;
use Dynamik\Modman\Graders\PerceptualHashGrader;
use Dynamik\Modman\Graders\Testing\FakeGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\Image;
use Dynamik\Modman\Support\ModerationContent;
use Illuminate\Support\Facades\Http;

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
    assertGraderConforms(fn (): DenylistGrader => new DenylistGrader(words: ['badword']));
});

it('FakeGrader conforms', function (): void {
    assertGraderConforms(fn (): FakeGrader => new FakeGrader);
});

it('HeuristicGrader conforms', function (): void {
    assertGraderConforms(fn (): HeuristicGrader => new HeuristicGrader);
});

it('LlmGrader conforms', function (): void {
    // Canned valid response — the conformance suite only calls grade() on supported
    // content (text), so a single 200 with a parseable verdict body covers both drivers.
    Http::fake([
        '*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'verdict' => 'approve',
                'severity' => 0.0,
                'reason' => 'clean',
                'categories' => [],
            ])]],
            'choices' => [['message' => ['content' => json_encode([
                'verdict' => 'approve',
                'severity' => 0.0,
                'reason' => 'clean',
                'categories' => [],
            ])]]],
        ]),
    ]);

    assertGraderConforms(fn (): LlmGrader => new LlmGrader(
        driver: 'anthropic',
        model: 'claude-haiku-4-5',
        promptTemplate: "Evaluate:\n{{content}}",
        apiKey: 'test-key',
        timeout: 5,
        maxTokens: 256,
    ));
});

it('OpenAiModerationGrader conforms', function (): void {
    Http::fake([
        'api.openai.com/v1/moderations' => Http::response([
            'results' => [[
                'flagged' => false,
                'categories' => ['hate' => false],
                'category_scores' => ['hate' => 0.01],
            ]],
        ]),
    ]);

    assertGraderConforms(fn (): OpenAiModerationGrader => new OpenAiModerationGrader(
        apiKey: 'test-key',
        model: 'omni-moderation-latest',
        timeout: 5,
    ));
});

it('PerceptualHashGrader conforms', function (): void {
    // Stub hasher returns a hash that does not collide with knownHashes — the
    // conformance suite never asserts a particular verdict, only that severity
    // sits in [0, 1] when supports() returns true.
    assertGraderConforms(fn (): PerceptualHashGrader => new PerceptualHashGrader(
        knownHashes: [],
        hasher: fn (): string => 'no-op-hash',
    ));
});
