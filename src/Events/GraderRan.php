<?php

declare(strict_types=1);

namespace Dynamik\Modman\Events;

use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;

final readonly class GraderRan
{
    public function __construct(
        public Report $report,
        public Decision $decision,
    ) {}
}
