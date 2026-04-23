<?php

declare(strict_types=1);

namespace Dynamik\Modman\Contracts;

use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;

interface Grader
{
    /**
     * Stable alias used in config (e.g. 'denylist', 'llm'). Must be
     * consistent across instances — `(new X)->key() === (new X)->key()`.
     */
    public function key(): string;

    /**
     * Whether this grader can evaluate the given content. Graders that
     * only handle text must return false when passed images-only content,
     * not throw.
     */
    public function supports(ModerationContent $content): bool;

    /**
     * Evaluate the content. Must only be called when supports() returned
     * true. Implementations may throw; the orchestrator catches and
     * records a Verdict(error, ...).
     */
    public function grade(ModerationContent $content, Report $report): Verdict;
}
