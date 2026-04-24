<?php

declare(strict_types=1);

namespace Dynamik\Modman\Graders\Testing;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;

final class RecordingGrader implements Grader
{
    /** @var list<array{content: ModerationContent, report: Report}> */
    public array $calls = [];

    public function __construct(private readonly Grader $inner) {}

    public function key(): string
    {
        return $this->inner->key();
    }

    public function supports(ModerationContent $content): bool
    {
        return $this->inner->supports($content);
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        $this->calls[] = ['content' => $content, 'report' => $report];

        return $this->inner->grade($content, $report);
    }
}
