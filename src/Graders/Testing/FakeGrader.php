<?php

declare(strict_types=1);

namespace Dynamik\Modman\Graders\Testing;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;

final class FakeGrader implements Grader
{
    private readonly string $key;

    private readonly Verdict $verdict;

    private readonly bool $supports;

    public function __construct(
        string $key = 'fake',
        ?Verdict $verdict = null,
        bool $supports = true,
    ) {
        $this->key = $key;
        $this->verdict = $verdict ?? new Verdict(VerdictKind::Approve, 0.0, 'fake');
        $this->supports = $supports;
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
