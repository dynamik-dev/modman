<?php

declare(strict_types=1);

namespace Dynamik\Modman\Events;

use Dynamik\Modman\Models\Report;
use Illuminate\Database\Eloquent\Model;

final readonly class ReportReopened
{
    public function __construct(
        public Report $report,
        public ?Model $actor,
        public ?string $reason,
    ) {}
}
