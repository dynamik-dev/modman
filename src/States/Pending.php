<?php

declare(strict_types=1);

namespace Dynamik\Modman\States;

final class Pending extends ReportState
{
    public static string $name = 'pending';

    public function label(): string
    {
        return 'Pending';
    }
}
