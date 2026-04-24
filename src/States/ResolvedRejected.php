<?php

declare(strict_types=1);

namespace Dynamik\Modman\States;

use Override;

final class ResolvedRejected extends ReportState
{
    public static string $name = 'resolved_rejected';

    public function label(): string
    {
        return 'Resolved (rejected)';
    }

    #[Override]
    public function isTerminal(): bool
    {
        return true;
    }
}
