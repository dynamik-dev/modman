<?php

declare(strict_types=1);

namespace Dynamik\Modman\Concerns;

use Dynamik\Modman\Events\ReportCreated;
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

        Event::dispatch(new ReportCreated($report));

        return $report;
    }
}
