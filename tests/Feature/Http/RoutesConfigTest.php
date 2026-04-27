<?php

declare(strict_types=1);

use Dynamik\Modman\Models\Report;
use Dynamik\Modman\ModmanServiceProvider;
use Illuminate\Http\Response;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

it('does not register routes when modman.routes.enabled is false', function (): void {
    config()->set('modman.routes.enabled', false);

    $provider = $this->app->getProvider(ModmanServiceProvider::class);
    expect($provider)->not->toBeNull();

    // Flush existing routes registered by the earlier boot, then re-boot so
    // the disabled flag is honored.
    $this->app['router']->setRoutes(new RouteCollection);
    $provider->boot();

    expect(Route::has('modman.reports.show'))->toBeFalse()
        ->and(Route::has('modman.reports.resolve'))->toBeFalse()
        ->and(Route::has('modman.reports.reopen'))->toBeFalse();
});

it('respects a custom middleware override on the modman route group', function (): void {
    config()->set('modman.routes.middleware', ['api', 'modman.test.deny']);

    $this->app['router']->aliasMiddleware('modman.test.deny', fn (): Response => new Response('blocked-by-custom-middleware', 418));

    $this->app['router']->setRoutes(new RouteCollection);
    $provider = $this->app->getProvider(ModmanServiceProvider::class);
    expect($provider)->not->toBeNull();
    $provider->boot();

    $report = Report::factory()->create(['state' => 'needs_human']);

    $this->getJson(route('modman.reports.show', $report))
        ->assertStatus(418);
});
