<?php

declare(strict_types=1);

namespace Dynamik\Modman\Transitions;

final class ToResolvedApproved extends LoggedTransition
{
    protected function toState(): string
    {
        return 'resolved_approved';
    }
}
