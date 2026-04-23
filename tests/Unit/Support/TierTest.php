<?php

declare(strict_types=1);

use Dynamik\Modman\Support\Tier;

it('has the five tier values', function (): void {
    expect(Tier::cases())->toHaveCount(5);
    expect(array_map(fn (Tier $t) => $t->value, Tier::cases()))
        ->toBe(['denylist', 'heuristic', 'hosted_classifier', 'llm', 'human']);
});
