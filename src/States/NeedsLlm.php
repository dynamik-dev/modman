<?php

declare(strict_types=1);

namespace Dynamik\Modman\States;

final class NeedsLlm extends ReportState
{
    public static string $name = 'needs_llm';

    public function label(): string
    {
        return 'Needs LLM review';
    }
}
