<?php

declare(strict_types=1);

namespace Dynamik\Modman\Policy;

use Dynamik\Modman\Contracts\ModerationPolicy;
use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\PolicyAction;
use Dynamik\Modman\Support\PolicyActions\Approve;
use Dynamik\Modman\Support\PolicyActions\EscalateTo;
use Dynamik\Modman\Support\PolicyActions\Reject;
use Dynamik\Modman\Support\PolicyActions\RouteToHuman;

final class ConfigDrivenPolicy implements ModerationPolicy
{
    /**
     * @param  list<string>  $pipeline  ordered grader keys
     */
    public function __construct(
        private readonly array $pipeline,
        private readonly float $autoRejectAt,
        private readonly float $autoApproveBelow,
    ) {}

    public function decide(Report $report, Decision $latest): PolicyAction
    {
        $severity = $latest->severity;
        $verdict = $latest->verdict;

        if ($verdict === 'reject' || ($severity !== null && $severity >= $this->autoRejectAt)) {
            return new Reject;
        }

        if ($verdict === 'approve' && $severity !== null && $severity < $this->autoApproveBelow) {
            return new Approve;
        }

        $next = $this->nextGrader($latest->grader);
        if ($next !== null) {
            return new EscalateTo($next);
        }

        return new RouteToHuman;
    }

    private function nextGrader(string $current): ?string
    {
        $index = array_search($current, $this->pipeline, true);
        if ($index === false) {
            return null;
        }

        return $this->pipeline[$index + 1] ?? null;
    }
}
