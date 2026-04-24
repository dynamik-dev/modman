<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\OpenAiModerationGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\VerdictKind;
use Illuminate\Support\Facades\Http;

it('rejects flagged content', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'results' => [[
                'flagged' => true,
                'categories' => ['hate' => true, 'violence' => false],
                'category_scores' => ['hate' => 0.92, 'violence' => 0.05],
            ]],
        ]),
    ]);

    $verdict = (new OpenAiModerationGrader(apiKey: 'x'))->grade(
        ModerationContent::make()->withText('something'),
        Report::factory()->make(),
    );

    expect($verdict->kind)->toBe(VerdictKind::Reject);
    expect($verdict->severity)->toBe(0.92);
});

it('approves unflagged content', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'results' => [[
                'flagged' => false,
                'categories' => ['hate' => false],
                'category_scores' => ['hate' => 0.01],
            ]],
        ]),
    ]);

    $verdict = (new OpenAiModerationGrader(apiKey: 'x'))->grade(
        ModerationContent::make()->withText('a kind message'),
        Report::factory()->make(),
    );

    expect($verdict->kind)->toBe(VerdictKind::Approve);
});
