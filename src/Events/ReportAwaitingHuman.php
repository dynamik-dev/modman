<?php

declare(strict_types=1);

namespace Dynamik\Modman\Events;

use Dynamik\Modman\Models\Report;

final readonly class ReportAwaitingHuman
{
    public function __construct(public Report $report) {}
}
