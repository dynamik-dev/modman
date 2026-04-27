<?php

declare(strict_types=1);

namespace Dynamik\Modman\Jobs;

use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Pipeline\Orchestrator;
use Dynamik\Modman\States\NeedsHuman;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\States\ResolvedRejected;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Transitions\ToNeedsHuman;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class RunModerationPipeline implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly string $reportId) {}

    public function handle(Orchestrator $orchestrator): void
    {
        $report = Report::query()->find($this->reportId);
        if ($report === null) {
            return;
        }

        $orchestrator->runNext($report);
    }

    public function failed(Throwable $e): void
    {
        $report = Report::query()->find($this->reportId);
        if ($report === null) {
            return;
        }

        $state = $report->state;
        if ($state instanceof ResolvedApproved || $state instanceof ResolvedRejected) {
            return;
        }

        // task-6: pipeline-system errors record tier='pipeline' instead of
        // hosted_classifier so audit data isn't misattributed to a grader tier.
        Decision::query()->create([
            'report_id' => $report->id,
            'grader' => 'pipeline',
            'tier' => 'pipeline',
            'verdict' => VerdictKind::Error->value,
            'severity' => null,
            'reason' => 'pipeline job failed: '.$e->getMessage(),
            'evidence' => ['exception' => $e::class, 'message' => $e->getMessage()],
        ]);

        if (! ($state instanceof NeedsHuman)) {
            $report->state->transition(new ToNeedsHuman($report));
        }
    }

    public static function queueName(): string
    {
        $name = config('modman.queue', 'modman');

        return is_string($name) ? $name : 'modman';
    }

    public static function connectionName(): ?string
    {
        $name = config('modman.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
