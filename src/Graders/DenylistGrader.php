<?php

declare(strict_types=1);

namespace Dynamik\Modman\Graders;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;
use Transliterator;

final class DenylistGrader implements Grader
{
    /** @var list<string> */
    private readonly array $words;

    /** @var list<string> */
    private readonly array $regex;

    private readonly bool $caseSensitive;

    private readonly ?Transliterator $transliterator;

    /**
     * @param  list<string>  $words
     * @param  list<string>  $regex
     */
    public function __construct(
        array $words = [],
        array $regex = [],
        bool $caseSensitive = false,
    ) {
        $this->words = $words;
        $this->regex = $regex;
        $this->caseSensitive = $caseSensitive;
        $this->transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
    }

    public function key(): string
    {
        return 'denylist';
    }

    public function supports(ModerationContent $content): bool
    {
        return $content->hasText();
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        $raw = (string) $content->text();
        $normalized = $this->normalize($raw);

        $matches = [];

        foreach ($this->words as $word) {
            $needle = $this->caseSensitive ? $word : $this->normalize($word);
            if ($needle !== '' && str_contains($normalized, $needle)) {
                $matches[] = $word;
            }
        }

        foreach ($this->regex as $pattern) {
            if (preg_match($pattern, $raw) === 1) {
                $matches[] = $pattern;
            }
        }

        if ($matches === []) {
            return new Verdict(VerdictKind::Approve, 0.0, 'no denylist hits');
        }

        return new Verdict(
            VerdictKind::Reject,
            0.95,
            'matched denylist entries',
            ['matches' => $matches],
        );
    }

    private function normalize(string $input): string
    {
        $transliterated = $this->transliterator?->transliterate($input);
        $text = ($transliterated === false || $transliterated === null) ? $input : $transliterated;

        return $this->caseSensitive ? $text : mb_strtolower($text);
    }
}
