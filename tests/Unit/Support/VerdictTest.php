<?php

declare(strict_types=1);

use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;

it('holds a kind, severity, reason, and evidence', function (): void {
    $v = new Verdict(VerdictKind::Approve, 0.1, 'looks fine', ['matched' => []]);
    expect($v->kind)->toBe(VerdictKind::Approve);
    expect($v->severity)->toBe(0.1);
    expect($v->reason)->toBe('looks fine');
    expect($v->evidence)->toBe(['matched' => []]);
});

it('rejects severity outside 0..1', function (): void {
    expect(fn () => new Verdict(VerdictKind::Approve, -0.1, 'bad'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new Verdict(VerdictKind::Approve, 1.1, 'bad'))
        ->toThrow(InvalidArgumentException::class);
});
