<?php

declare(strict_types=1);

namespace Dynamik\Modman\Transitions;

final class ToScreening extends LoggedTransition
{
    protected function toState(): string
    {
        return 'screening';
    }
}
