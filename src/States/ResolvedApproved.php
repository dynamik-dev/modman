<?php

declare(strict_types=1);

namespace Dynamik\Modman\States;

use Override;

final class ResolvedApproved extends ReportState
{
    public static string $name = 'resolved_approved';

    public function label(): string
    {
        return 'Resolved (approved)';
    }

    #[Override]
    public function isTerminal(): bool
    {
        return true;
    }
}
