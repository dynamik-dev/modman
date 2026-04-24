<?php

declare(strict_types=1);

namespace Dynamik\Modman\Jobs;

use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Pipeline\Orchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RunModerationPipeline implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $reportId) {}

    public function handle(Orchestrator $orchestrator): void
    {
        $report = Report::query()->find($this->reportId);
        if ($report === null) {
            return;
        }

        $orchestrator->runNext($report);
    }
}
