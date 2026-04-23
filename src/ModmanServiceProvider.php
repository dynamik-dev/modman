<?php

declare(strict_types=1);

namespace Dynamik\Modman;

use Illuminate\Support\ServiceProvider;

final class ModmanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modman.php', 'modman');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/modman.php' => config_path('modman.php'),
            ], 'modman-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'modman-migrations');
        }
    }
}
