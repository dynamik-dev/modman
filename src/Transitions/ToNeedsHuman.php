<?php

declare(strict_types=1);

namespace Dynamik\Modman\Transitions;

final class ToNeedsHuman extends LoggedTransition
{
    protected function toState(): string
    {
        return 'needs_human';
    }
}
