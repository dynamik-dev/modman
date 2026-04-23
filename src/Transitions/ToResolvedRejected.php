<?php

declare(strict_types=1);

namespace Dynamik\Modman\Transitions;

final class ToResolvedRejected extends LoggedTransition
{
    protected function toState(): string
    {
        return 'resolved_rejected';
    }
}
