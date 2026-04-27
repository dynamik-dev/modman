<?php

declare(strict_types=1);

namespace Dynamik\Modman\Graders;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class LlmGrader implements Grader
{
    public function __construct(
        private string $driver,
        private string $model,
        private string $promptTemplate,
        private string $apiKey,
        private int $timeout = 15,
        private int $maxTokens = 512,
    ) {}

    public function key(): string
    {
        return 'llm';
    }

    public function supports(ModerationContent $content): bool
    {
        return $content->hasText();
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        if (trim($this->apiKey) === '') {
            return new Verdict(
                VerdictKind::Error,
                0.0,
                'LLM grader not configured',
                ['hint' => 'set MODMAN_LLM_API_KEY'],
            );
        }

        $prompt = str_replace('{{content}}', $this->renderContent($content), $this->promptTemplate);

        try {
            $raw = match ($this->driver) {
                'anthropic' => $this->callAnthropic($prompt),
                'openai' => $this->callOpenAi($prompt),
                default => throw new RuntimeException("Unknown LLM driver: {$this->driver}"),
            };
        } catch (Throwable $e) {
            return new Verdict(
                VerdictKind::Error,
                0.0,
                'LLM call failed',
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );
        }

        return $this->parseVerdict($raw);
    }

    private function renderContent(ModerationContent $content): string
    {
        return $content->hasText() ? 'TEXT: '.$content->text() : '';
    }

    private function callAnthropic(string $prompt): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout($this->timeout)
            ->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ])
            ->throw();

        $text = $response->json('content.0.text');

        return is_string($text) ? $text : '';
    }

    private function callOpenAi(string $prompt): string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
            ])
            ->throw();

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : '';
    }

    private function parseVerdict(string $raw): Verdict
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new Verdict(
                VerdictKind::Error,
                0.0,
                'LLM returned non-JSON',
                ['raw' => $raw, 'message' => $e->getMessage()],
            );
        }

        $verdict = is_string($data['verdict'] ?? null) ? $data['verdict'] : null;
        $severity = isset($data['severity']) && is_numeric($data['severity'])
            ? (float) $data['severity']
            : null;
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
        $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];

        if ($verdict === null || $severity === null) {
            return new Verdict(
                VerdictKind::Error,
                0.0,
                'LLM response missing required fields',
                ['raw' => $data],
            );
        }

        $kind = match ($verdict) {
            'approve' => VerdictKind::Approve,
            'reject' => VerdictKind::Reject,
            'inconclusive' => VerdictKind::Inconclusive,
            default => VerdictKind::Error,
        };

        return new Verdict(
            $kind,
            max(0.0, min(1.0, $severity)),
            $reason,
            ['categories' => $categories],
        );
    }
}
