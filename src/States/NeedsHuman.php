<?php

declare(strict_types=1);

namespace Dynamik\Modman\States;

final class NeedsHuman extends ReportState
{
    public static string $name = 'needs_human';

    public function label(): string
    {
        return 'Needs human review';
    }
}
