<?php

declare(strict_types=1);

namespace Dynamik\Modman\Transitions;

final class ToNeedsLlm extends LoggedTransition
{
    protected function toState(): string
    {
        return 'needs_llm';
    }
}
