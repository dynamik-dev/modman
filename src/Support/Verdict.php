<?php

declare(strict_types=1);

namespace Dynamik\Modman\Support;

use InvalidArgumentException;

final readonly class Verdict
{
    /** @param  array<string, mixed>  $evidence */
    public function __construct(
        public VerdictKind $kind,
        public float $severity,
        public string $reason,
        public array $evidence = [],
    ) {
        if ($severity < 0.0 || $severity > 1.0) {
            throw new InvalidArgumentException(
                "Verdict severity must be in [0.0, 1.0], got {$severity}"
            );
        }
    }
}
