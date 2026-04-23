<?php

declare(strict_types=1);

namespace Dynamik\Modman\Events;

use Dynamik\Modman\Models\Report;

final readonly class ReportTransitioned
{
    public function __construct(
        public Report $report,
        public string $from,
        public string $to,
    ) {}
}
