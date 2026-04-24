<?php

declare(strict_types=1);

namespace Dynamik\Modman\Pipeline;

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Contracts\ModerationPolicy;
use Dynamik\Modman\Contracts\Reportable;
use Dynamik\Modman\Events\GraderRan;
use Dynamik\Modman\Events\ReportAwaitingHuman;
use Dynamik\Modman\Events\ReportResolved;
use Dynamik\Modman\Jobs\RunModerationPipeline;
use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\States\NeedsHuman;
use Dynamik\Modman\States\NeedsLlm;
use Dynamik\Modman\States\Pending;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\PolicyActions\Approve;
use Dynamik\Modman\Support\PolicyActions\EscalateTo;
use Dynamik\Modman\Support\PolicyActions\Reject;
use Dynamik\Modman\Support\PolicyActions\RouteToHuman;
use Dynamik\Modman\Support\Tier;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Transitions\ToNeedsHuman;
use Dynamik\Modman\Transitions\ToNeedsLlm;
use Dynamik\Modman\Transitions\ToResolvedApproved;
use Dynamik\Modman\Transitions\ToResolvedRejected;
use Dynamik\Modman\Transitions\ToScreening;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

final readonly class Orchestrator
{
    public function __construct(
        private Container $container,
        private ModerationPolicy $policy,
        private Config $config,
    ) {}

    public function runNext(Report $report): void
    {
        if ($report->state->isTerminal()) {
            return;
        }

        if ($report->state instanceof Pending) {
            $report->state->transition(new ToScreening($report));
            $report = $report->refresh();
        }

        $graderKey = $this->currentGraderKey($report);
        if ($graderKey === null) {
            $this->routeToHuman($report);

            return;
        }

        $grader = $this->resolveGrader($graderKey);
        $content = $this->loadContent($report);

        if (! $grader->supports($content)) {
            $this->advance($report, $graderKey);

            return;
        }

        try {
            $verdict = $grader->grade($content, $report);
        } catch (Throwable $e) {
            $verdict = new Verdict(
                VerdictKind::Error,
                0.0,
                'grader threw: '.$e->getMessage(),
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );
        }

        $decision = Decision::query()->create([
            'report_id' => $report->id,
            'grader' => $graderKey,
            'tier' => $this->tierFor($graderKey),
            'verdict' => $verdict->kind->value,
            'severity' => $verdict->kind === VerdictKind::Error ? null : $verdict->severity,
            'reason' => $verdict->reason,
            'evidence' => $verdict->evidence,
        ]);

        Event::dispatch(new GraderRan($report, $decision));

        $action = $this->policy->decide($report, $decision);

        match (true) {
            $action instanceof Approve => $this->resolveApproved($report),
            $action instanceof Reject => $this->resolveRejected($report),
            $action instanceof EscalateTo => $this->escalate($report, $action->graderKey),
            $action instanceof RouteToHuman => $this->routeToHuman($report),
            default => throw new RuntimeException('Unknown policy action: '.$action::class),
        };
    }

    private function currentGraderKey(Report $report): ?string
    {
        $state = $report->state->getValue();
        $pipeline = (array) $this->config->get('modman.pipeline', []);
        $keys = array_map(strval(...), array_keys($pipeline));

        if ($state === 'needs_llm') {
            return 'llm';
        }

        if ($state !== 'screening') {
            return null;
        }

        $used = [];
        foreach ($report->decisions()->pluck('grader')->all() as $grader) {
            if (is_scalar($grader)) {
                $used[] = (string) $grader;
            }
        }

        foreach ($keys as $key) {
            if (! in_array($key, $used, true)) {
                return $key;
            }
        }

        return null;
    }

    private function resolveGrader(string $key): Grader
    {
        $pipeline = (array) $this->config->get('modman.pipeline', []);
        $class = $pipeline[$key] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            throw new RuntimeException("No grader class configured for key '{$key}'");
        }

        /** @var Grader $grader */
        $grader = $this->container->make($class);

        return $grader;
    }

    private function loadContent(Report $report): ModerationContent
    {
        /** @var Model|null $reportable */
        $reportable = $report->reportable;
        if (! $reportable instanceof Reportable) {
            return ModerationContent::make();
        }

        return $reportable->toModerationContent();
    }

    private function tierFor(string $graderKey): string
    {
        return match ($graderKey) {
            'denylist' => Tier::Denylist->value,
            'heuristic' => Tier::Heuristic->value,
            'hosted_classifier' => Tier::HostedClassifier->value,
            'llm' => Tier::Llm->value,
            default => Tier::HostedClassifier->value,
        };
    }

    private function resolveApproved(Report $report): void
    {
        $report->state->transition(new ToResolvedApproved($report));
        $fresh = $report->fresh() ?? $report;
        $fresh->resolved_at = now();
        $fresh->save();
        Event::dispatch(new ReportResolved($fresh, VerdictKind::Approve));
    }

    private function resolveRejected(Report $report): void
    {
        $report->state->transition(new ToResolvedRejected($report));
        $fresh = $report->fresh() ?? $report;
        $fresh->resolved_at = now();
        $fresh->save();
        Event::dispatch(new ReportResolved($fresh, VerdictKind::Reject));
    }

    private function escalate(Report $report, string $nextGraderKey): void
    {
        if ($nextGraderKey === 'llm' && ! ($report->state instanceof NeedsLlm)) {
            $report->state->transition(new ToNeedsLlm($report));
            $report = $report->refresh();
        }

        $this->dispatchNext($report);
    }

    private function routeToHuman(Report $report): void
    {
        if (! ($report->state instanceof NeedsHuman)) {
            $report->state->transition(new ToNeedsHuman($report));
            $report = $report->refresh();
        }

        Event::dispatch(new ReportAwaitingHuman($report));
    }

    private function advance(Report $report, string $skippedKey): void
    {
        Decision::query()->create([
            'report_id' => $report->id,
            'grader' => $skippedKey,
            'tier' => $this->tierFor($skippedKey),
            'verdict' => VerdictKind::Skipped->value,
            'severity' => null,
            'reason' => 'grader does not support this content',
            'evidence' => ['skipped' => true],
        ]);

        $this->dispatchNext($report);
    }

    private function dispatchNext(Report $report): void
    {
        RunModerationPipeline::dispatch($report->id)
            ->onQueue(RunModerationPipeline::queueName());
    }
}
