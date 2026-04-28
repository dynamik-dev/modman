<?php

declare(strict_types=1);

namespace Dynamik\Modman\Concerns;

use Dynamik\Modman\Events\ReportCreated;
use Dynamik\Modman\Jobs\RunModerationPipeline;
use Dynamik\Modman\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Host models: apply this trait and implement Dynamik\Modman\Contracts\Reportable.
 */
trait Reportable
{
    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public function report(?Model $reporter = null, ?string $reason = null): Report
    {
        $reporterKeyRaw = $reporter?->getKey();
        $reporterId = is_scalar($reporterKeyRaw) ? (string) $reporterKeyRaw : null;

        $ownKeyRaw = $this->getKey();
        $ownId = is_scalar($ownKeyRaw) ? (string) $ownKeyRaw : null;

        $report = DB::transaction(fn (): Report => Report::query()->create([
            'reportable_type' => $this->getMorphClass(),
            'reportable_id' => $ownId,
            'reporter_type' => $reporter?->getMorphClass(),
            'reporter_id' => $reporterId,
            'reason' => $reason,
            'state' => 'pending',
        ]));

        // Defer both the domain event and the pipeline-job enqueue to commit.
        // If a host wraps this call in a larger transaction that rolls back,
        // listeners must NOT see a phantom Report and the queue must NOT receive
        // a job pointing at a row that never persisted. DB::afterCommit() runs
        // immediately when there is no active transaction.
        DB::afterCommit(static function () use ($report): void {
            Event::dispatch(new ReportCreated($report));

            RunModerationPipeline::dispatch($report->id)
                ->onConnection(RunModerationPipeline::connectionName())
                ->onQueue(RunModerationPipeline::queueName());
        });

        return $report;
    }
}
