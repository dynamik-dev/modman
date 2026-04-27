<?php

declare(strict_types=1);

use Dynamik\Modman\ModmanServiceProvider;

// task-26: a misconfigured modman.policy class surfaces at register-time so a
// typo cannot escape into a moderation tick and bubble through the queue's
// failed() path.
it('throws at register when modman.policy points to a non-existent class', function (): void {
    config()->set('modman.policy', 'App\\Nonexistent\\PolicyClass');

    $provider = new ModmanServiceProvider(app());

    expect(fn () => $provider->register())
        ->toThrow(InvalidArgumentException::class, 'modman.policy');
});

it('throws at register when modman.policy class does not implement ModerationPolicy', function (): void {
    config()->set('modman.policy', stdClass::class);

    $provider = new ModmanServiceProvider(app());

    expect(fn () => $provider->register())
        ->toThrow(InvalidArgumentException::class, 'must implement');
});

// task-18: malformed denylist regex surfaces at boot, not silently inside the
// grader (where preg_match=>false would be misread as "no match").
it('throws at boot when modman.graders.denylist.regex contains an invalid pattern', function (): void {
    config()->set('modman.graders.denylist.regex', ['/this(/']);

    $provider = new ModmanServiceProvider(app());

    expect(fn () => $provider->boot())
        ->toThrow(InvalidArgumentException::class, 'invalid pattern');
});

it('does not throw when modman.graders.denylist.regex is empty or absent', function (): void {
    config()->set('modman.graders.denylist.regex', []);

    $provider = new ModmanServiceProvider(app());
    $provider->boot();

    expect(true)->toBeTrue();
});
