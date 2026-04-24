<?php

declare(strict_types=1);

namespace Dynamik\Modman\Graders;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenAiModerationGrader implements Grader
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'omni-moderation-latest',
        private readonly int $timeout = 10,
    ) {}

    public function key(): string
    {
        return 'openai_moderation';
    }

    public function supports(ModerationContent $content): bool
    {
        return $content->hasText() || $content->hasImages();
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post('https://api.openai.com/v1/moderations', [
                    'model' => $this->model,
                    'input' => (string) $content->text(),
                ])
                ->throw();
        } catch (Throwable $e) {
            return new Verdict(VerdictKind::Error, 0.0, 'moderation call failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $result = $response->json('results.0');
        if (! is_array($result)) {
            return new Verdict(VerdictKind::Error, 0.0, 'malformed response');
        }

        $flagged = (bool) ($result['flagged'] ?? false);
        $scores = is_array($result['category_scores'] ?? null) ? $result['category_scores'] : [];
        $maxScore = 0.0;
        foreach ($scores as $score) {
            if (is_numeric($score)) {
                $maxScore = max($maxScore, (float) $score);
            }
        }

        $categories = is_array($result['categories'] ?? null) ? $result['categories'] : [];

        return new Verdict(
            $flagged ? VerdictKind::Reject : VerdictKind::Approve,
            $maxScore,
            $flagged ? 'openai moderation flagged' : 'openai moderation clear',
            ['categories' => $categories, 'scores' => $scores],
        );
    }
}
