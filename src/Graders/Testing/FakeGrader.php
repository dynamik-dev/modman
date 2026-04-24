<?php

declare(strict_types=1);

namespace Dynamik\Modman\Graders\Testing;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;

final readonly class FakeGrader implements Grader
{
    private Verdict $verdict;

    public function __construct(
        private string $key = 'fake',
        ?Verdict $verdict = null,
        private bool $supports = true,
    ) {
        $this->verdict = $verdict ?? new Verdict(VerdictKind::Approve, 0.0, 'fake');
    }

    public function key(): string
    {
        return $this->key;
    }

    public function supports(ModerationContent $content): bool
    {
        return $this->supports;
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        return $this->verdict;
    }
}
