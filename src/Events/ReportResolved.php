<?php

declare(strict_types=1);

namespace Dynamik\Modman\Events;

use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\VerdictKind;

final readonly class ReportResolved
{
    public function __construct(
        public Report $report,
        public VerdictKind $outcome,
    ) {}
}
