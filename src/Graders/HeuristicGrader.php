<?php

declare(strict_types=1);

namespace Dynamik\Modman\Graders;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;

final readonly class HeuristicGrader implements Grader
{
    public function key(): string
    {
        return 'heuristic';
    }

    public function supports(ModerationContent $content): bool
    {
        return $content->hasText();
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        $text = (string) $content->text();
        $len = mb_strlen($text);
        if ($len < 8) {
            return new Verdict(VerdictKind::Approve, 0.0, 'too short');
        }

        $severity = 0.0;
        $signals = [];

        $upper = preg_match_all('/[A-Z]/', $text);
        $lower = preg_match_all('/[a-z]/', $text);
        if ($upper === false) {
            $upper = 0;
        }
        if ($lower === false) {
            $lower = 0;
        }
        $letters = $upper + $lower;
        if ($letters > 0 && ($upper / $letters) > 0.8) {
            $severity += 0.4;
            $signals[] = 'all_caps';
        }

        $links = preg_match_all('#https?://\S+#i', $text);
        if ($links === false) {
            $links = 0;
        }
        if ($links >= 3 && $len < 200) {
            $severity += 0.5;
            $signals[] = 'link_density';
        }

        if (preg_match('/(.)\1{5,}/u', $text) === 1) {
            $severity += 0.3;
            $signals[] = 'repeated_chars';
        }

        $severity = min(1.0, $severity);

        if ($signals === []) {
            return new Verdict(VerdictKind::Approve, 0.0, 'no heuristic hits');
        }

        return new Verdict(
            VerdictKind::Inconclusive,
            $severity,
            'heuristic signals: '.implode(',', $signals),
            ['signals' => $signals],
        );
    }
}
