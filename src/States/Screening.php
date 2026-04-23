<?php

declare(strict_types=1);

namespace Dynamik\Modman\States;

final class Screening extends ReportState
{
    public static string $name = 'screening';

    public function label(): string
    {
        return 'Screening';
    }
}
