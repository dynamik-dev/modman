<?php

declare(strict_types=1);

namespace Dynamik\Modman\Contracts;

use Dynamik\Modman\Support\ModerationContent;

interface Reportable
{
    public function toModerationContent(): ModerationContent;
}
