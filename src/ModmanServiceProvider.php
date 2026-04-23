<?php

declare(strict_types=1);

namespace Dynamik\Modman;

use Dynamik\Modman\Graders\DenylistGrader;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ModmanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modman.php', 'modman');

        $this->app->bind(DenylistGrader::class, function (Application $app): DenylistGrader {
            /** @var ConfigRepository $repo */
            $repo = $app->make('config');
            /** @var array<string, mixed> $config */
            $config = (array) $repo->get('modman.graders.denylist', []);

            /** @var list<string> $words */
            $words = is_array($config['words'] ?? null) ? array_values($config['words']) : [];
            $path = is_string($config['words_path'] ?? null) ? $config['words_path'] : null;

            if ($path !== null && is_file($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '' && ! str_starts_with($line, '#')) {
                        $words[] = $line;
                    }
                }
            }

            /** @var list<string> $regex */
            $regex = is_array($config['regex'] ?? null) ? array_values($config['regex']) : [];
            $caseSensitive = (bool) ($config['case_sensitive'] ?? false);

            return new DenylistGrader(array_values(array_unique($words)), $regex, $caseSensitive);
        });
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

            $this->publishes([
                __DIR__.'/../resources/modman' => resource_path('modman'),
            ], 'modman-resources');
        }
    }
}
