<?php

declare(strict_types=1);

namespace Dynamik\Modman\Support\PolicyActions;

use Dynamik\Modman\Support\PolicyAction;

final readonly class EscalateTo implements PolicyAction
{
    public function __construct(public string $graderKey) {}
}
