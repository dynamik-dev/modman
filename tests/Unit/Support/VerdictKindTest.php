<?php

declare(strict_types=1);

use Dynamik\Modman\Support\VerdictKind;

it('has the four canonical verdicts', function (): void {
    expect(VerdictKind::cases())->toHaveCount(4);
    expect(VerdictKind::Approve->value)->toBe('approve');
    expect(VerdictKind::Reject->value)->toBe('reject');
    expect(VerdictKind::Inconclusive->value)->toBe('inconclusive');
    expect(VerdictKind::Error->value)->toBe('error');
});
