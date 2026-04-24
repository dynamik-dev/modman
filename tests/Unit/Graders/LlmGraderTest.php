<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\LlmGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\VerdictKind;
use Illuminate\Support\Facades\Http;

function makeLlmGrader(string $driver = 'anthropic'): LlmGrader
{
    return new LlmGrader(
        driver: $driver,
        model: 'claude-haiku-4-5',
        promptTemplate: "Evaluate:\n{{content}}",
        apiKey: 'test-key',
        timeout: 5,
        maxTokens: 256,
    );
}

it('supports text content', function (): void {
    expect(makeLlmGrader()->supports(ModerationContent::make()->withText('hi')))->toBeTrue();
    expect(makeLlmGrader()->supports(ModerationContent::make()))->toBeFalse();
});

it('returns a Reject verdict when Anthropic returns reject', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'verdict' => 'reject',
                    'severity' => 0.95,
                    'reason' => 'hate speech',
                    'categories' => ['hate'],
                ]),
            ]],
        ]),
    ]);

    $verdict = makeLlmGrader()->grade(
        ModerationContent::make()->withText('die'),
        Report::factory()->make(),
    );

    expect($verdict->kind)->toBe(VerdictKind::Reject);
    expect($verdict->severity)->toBe(0.95);
    expect($verdict->evidence['categories'])->toBe(['hate']);
});

it('returns Error when the provider returns non-JSON', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'not json at all']],
        ]),
    ]);

    $verdict = makeLlmGrader()->grade(
        ModerationContent::make()->withText('hi'),
        Report::factory()->make(),
    );

    expect($verdict->kind)->toBe(VerdictKind::Error);
});

it('works against the OpenAI driver shape', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'verdict' => 'inconclusive',
                        'severity' => 0.4,
                        'reason' => 'ambiguous',
                        'categories' => ['harassment'],
                    ]),
                ],
            ]],
        ]),
    ]);

    $verdict = makeLlmGrader('openai')->grade(
        ModerationContent::make()->withText('maybe rude'),
        Report::factory()->make(),
    );

    expect($verdict->kind)->toBe(VerdictKind::Inconclusive);
});
