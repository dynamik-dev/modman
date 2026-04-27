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
use Dynamik\Modman\Support\VerdictKind;

final readonly class ConfigDrivenPolicy implements ModerationPolicy
{
    /**
     * @param  list<string>  $pipeline  ordered grader keys
     */
    public function __construct(
        private array $pipeline,
        private float $autoRejectAt,
        private float $autoApproveBelow,
    ) {}

    public function decide(Report $report, Decision $latest): PolicyAction
    {
        $severity = $latest->severity;
        $verdict = $latest->verdict;

        if ($verdict === VerdictKind::Reject || ($severity !== null && $severity >= $this->autoRejectAt)) {
            return new Reject;
        }

        // task-25: inclusive at the boundary. A grader returning approve at exactly
        // `auto_approve_below` auto-approves; this matches the "anything strictly
        // higher needs more scrutiny" mental model most users carry. The reject side
        // already uses `>=` so both bounds are now inclusive of the threshold.
        if ($verdict === VerdictKind::Approve && $severity !== null && $severity <= $this->autoApproveBelow) {
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
