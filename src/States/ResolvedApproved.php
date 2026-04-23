<?php

declare(strict_types=1);

namespace Dynamik\Modman\States;

final class ResolvedApproved extends ReportState
{
    public static string $name = 'resolved_approved';

    public function label(): string
    {
        return 'Resolved (approved)';
    }

    public function isTerminal(): bool
    {
        return true;
    }
}
