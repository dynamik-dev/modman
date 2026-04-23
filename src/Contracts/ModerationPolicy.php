<?php

declare(strict_types=1);

namespace Dynamik\Modman\Contracts;

use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\PolicyAction;

interface ModerationPolicy
{
    /**
     * Decide what to do after a grader has produced a Decision.
     * Must not mutate the Report or Decision — returns an action only.
     */
    public function decide(Report $report, Decision $latest): PolicyAction;
}
