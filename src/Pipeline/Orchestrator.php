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
use Dynamik\Modman\States\ReportState;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\States\ResolvedRejected;
use Dynamik\Modman\States\Screening;
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
use Illuminate\Support\Facades\DB;
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
        if ($report->state->isTerminal() || $report->state instanceof NeedsHuman) {
            return;
        }

        $outcome = DB::transaction(function () use ($report): ?string {
            $locked = Report::query()->lockForUpdate()->find($report->id);
            if ($locked === null) {
                return null;
            }

            return $this->step($locked);
        });

        if ($outcome === null) {
            return;
        }

        $fresh = Report::query()->find($report->id);
        if ($fresh === null) {
            return;
        }

        match ($outcome) {
            'resolved_approved' => Event::dispatch(new ReportResolved($fresh, VerdictKind::Approve)),
            'resolved_rejected' => Event::dispatch(new ReportResolved($fresh, VerdictKind::Reject)),
            'needs_human' => Event::dispatch(new ReportAwaitingHuman($fresh)),
            'escalate' => $this->dispatchNext($fresh->id),
            default => null,
        };
    }

    private function step(Report $report): ?string
    {
        if ($report->state->isTerminal() || $report->state instanceof NeedsHuman) {
            return null;
        }

        if ($report->state instanceof Pending) {
            $this->guardedTransition($report, Screening::class);
        }

        $graderKey = $this->currentGraderKey($report);
        if ($graderKey === null) {
            return $this->routeToHuman($report);
        }

        $grader = $this->resolveGrader($graderKey);
        // task-35: graders are self-identifying; assert the resolved grader's key()
        // matches the configured key so renames in config('modman.pipeline') fail loudly.
        if ($grader->key() !== $graderKey) {
            throw new RuntimeException(
                "Grader key mismatch for '{$graderKey}': "
                .$grader::class.'::key() returned '.$grader->key().'.'
            );
        }

        $content = $this->loadContent($report);

        if (! $grader->supports($content)) {
            $this->advance($report, $grader->key());

            return 'escalate';
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

        // task-8: soft uniqueness on (report_id, grader) for automated graders.
        // The row lock from task-7 closes the race; firstOrCreate makes replays no-ops.
        $decision = Decision::query()->firstOrCreate(
            ['report_id' => $report->id, 'grader' => $grader->key()],
            [
                'tier' => $this->tierFor($grader->key()),
                'verdict' => $verdict->kind->value,
                'severity' => $verdict->kind === VerdictKind::Error ? null : $verdict->severity,
                'reason' => $verdict->reason,
                'evidence' => $verdict->evidence,
            ],
        );

        Event::dispatch(new GraderRan($report, $decision));

        $action = $this->policy->decide($report, $decision);

        return match (true) {
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

        $used = [];
        foreach ($report->decisions()->pluck('grader')->all() as $grader) {
            if (is_scalar($grader)) {
                $used[] = (string) $grader;
            }
        }

        // task-1: needs_llm only routes to 'llm' when llm has not produced a decision yet.
        // Otherwise the loop ['denylist','llm','openai_moderation'] would re-run llm forever.
        if ($state === 'needs_llm') {
            return in_array('llm', $used, true) ? null : 'llm';
        }

        if ($state !== 'screening') {
            return null;
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
        // task-6: unknown grader keys record their key as the tier instead of
        // claiming hosted_classifier — that label was misleading audit data
        // for consumer-supplied graders.
        return match ($graderKey) {
            'denylist' => Tier::Denylist->value,
            'heuristic' => Tier::Heuristic->value,
            'hosted_classifier' => Tier::HostedClassifier->value,
            'llm' => Tier::Llm->value,
            default => $graderKey,
        };
    }

    private function resolveApproved(Report $report): string
    {
        $this->guardedTransition($report, ResolvedApproved::class);
        $report->newQuery()->whereKey($report->getKey())->update(['resolved_at' => now()]);

        return 'resolved_approved';
    }

    private function resolveRejected(Report $report): string
    {
        $this->guardedTransition($report, ResolvedRejected::class);
        $report->newQuery()->whereKey($report->getKey())->update(['resolved_at' => now()]);

        return 'resolved_rejected';
    }

    private function escalate(Report $report, string $nextGraderKey): string
    {
        // task-5: only honored target is 'llm'. Validate the key against the
        // configured pipeline so unknown advisory keys fail loudly instead of
        // silently stalling.
        $pipeline = (array) $this->config->get('modman.pipeline', []);
        $keys = array_map(strval(...), array_keys($pipeline));
        if (! in_array($nextGraderKey, $keys, true)) {
            throw new RuntimeException(
                "EscalateTo target '{$nextGraderKey}' is not in config('modman.pipeline')."
            );
        }

        if ($nextGraderKey === 'llm' && ! ($report->state instanceof NeedsLlm)) {
            $this->guardedTransition($report, NeedsLlm::class);
        }

        return 'escalate';
    }

    private function routeToHuman(Report $report): string
    {
        if (! ($report->state instanceof NeedsHuman)) {
            $this->guardedTransition($report, NeedsHuman::class);
        }

        return 'needs_human';
    }

    private function advance(Report $report, string $skippedKey): void
    {
        Decision::query()->firstOrCreate(
            ['report_id' => $report->id, 'grader' => $skippedKey],
            [
                'tier' => $this->tierFor($skippedKey),
                'verdict' => VerdictKind::Skipped->value,
                'severity' => null,
                'reason' => 'grader does not support this content',
                'evidence' => ['skipped' => true],
            ],
        );
    }

    /**
     * @param  class-string<ReportState>  $target
     */
    private function guardedTransition(Report $report, string $target): void
    {
        // task-36: gate every orchestrator-driven transition on canTransitionTo
        // so undeclared transitions surface as errors instead of silently working.
        if (! $report->state->canTransitionTo($target)) {
            throw new RuntimeException(
                'Illegal transition '.($report->state)." -> {$target}"
            );
        }

        $transitionClass = match ($target) {
            Screening::class => ToScreening::class,
            ResolvedApproved::class => ToResolvedApproved::class,
            ResolvedRejected::class => ToResolvedRejected::class,
            NeedsLlm::class => ToNeedsLlm::class,
            NeedsHuman::class => ToNeedsHuman::class,
            default => throw new RuntimeException("No transition wired for {$target}"),
        };

        $report->state->transition(new $transitionClass($report));
        $report->refresh();
    }

    private function dispatchNext(string $reportId): void
    {
        RunModerationPipeline::dispatch($reportId)
            ->onConnection(RunModerationPipeline::connectionName())
            ->onQueue(RunModerationPipeline::queueName());
    }
}
