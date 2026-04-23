<?php

declare(strict_types=1);

namespace Dynamik\Modman\States;

use Dynamik\Modman\Models\Report;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * @extends State<Report>
 */
abstract class ReportState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Screening::class)
            ->allowTransition(Screening::class, ResolvedApproved::class)
            ->allowTransition(Screening::class, ResolvedRejected::class)
            ->allowTransition(Screening::class, NeedsLlm::class)
            ->allowTransition(NeedsLlm::class, ResolvedApproved::class)
            ->allowTransition(NeedsLlm::class, ResolvedRejected::class)
            ->allowTransition(NeedsLlm::class, NeedsHuman::class)
            ->allowTransition(NeedsHuman::class, ResolvedApproved::class)
            ->allowTransition(NeedsHuman::class, ResolvedRejected::class)
            ->allowTransition(ResolvedApproved::class, NeedsHuman::class)
            ->allowTransition(ResolvedRejected::class, NeedsHuman::class)
            ->registerState([
                Pending::class,
                Screening::class,
                NeedsLlm::class,
                NeedsHuman::class,
                ResolvedApproved::class,
                ResolvedRejected::class,
            ]);
    }

    abstract public function label(): string;

    public function isTerminal(): bool
    {
        return false;
    }
}
