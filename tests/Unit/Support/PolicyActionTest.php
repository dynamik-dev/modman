<?php

declare(strict_types=1);

use Dynamik\Modman\Support\PolicyAction;
use Dynamik\Modman\Support\PolicyActions\Approve;
use Dynamik\Modman\Support\PolicyActions\EscalateTo;
use Dynamik\Modman\Support\PolicyActions\Reject;
use Dynamik\Modman\Support\PolicyActions\RouteToHuman;

it('all four actions implement the PolicyAction contract', function (): void {
    expect(new Approve)->toBeInstanceOf(PolicyAction::class);
    expect(new Reject)->toBeInstanceOf(PolicyAction::class);
    expect(new EscalateTo('llm'))->toBeInstanceOf(PolicyAction::class);
    expect(new RouteToHuman)->toBeInstanceOf(PolicyAction::class);
});

it('EscalateTo exposes the grader key', function (): void {
    $action = new EscalateTo('llm');
    expect($action->graderKey)->toBe('llm');
});
