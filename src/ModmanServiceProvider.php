<?php

declare(strict_types=1);

namespace Dynamik\Modman;

use Dynamik\Modman\Contracts\ModerationPolicy;
use Dynamik\Modman\Graders\DenylistGrader;
use Dynamik\Modman\Graders\LlmGrader;
use Dynamik\Modman\Policy\ConfigDrivenPolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Override;

final class ModmanServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modman.php', 'modman');

        $this->app->bind(DenylistGrader::class, function (Application $app): DenylistGrader {
            $repo = $app->make('config');
            /** @var array<string, mixed> $config */
            $config = (array) $repo->get('modman.graders.denylist', []);

            /** @var list<string> $words */
            $words = is_array($config['words'] ?? null) ? array_values($config['words']) : [];
            $path = is_string($config['words_path'] ?? null) ? $config['words_path'] : null;

            if ($path !== null && is_file($path)) {
                $loaded = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $lines = $loaded === false ? [] : $loaded;
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

        $this->app->bind(LlmGrader::class, function (Application $app): LlmGrader {
            $repo = $app->make('config');
            /** @var array<string, mixed> $config */
            $config = (array) $repo->get('modman.graders.llm', []);

            $promptPath = is_string($config['prompt'] ?? null) ? $config['prompt'] : null;
            $prompt = $promptPath !== null && is_file($promptPath)
                ? (string) file_get_contents($promptPath)
                : '{{content}}';

            $apiKey = is_string($config['api_key'] ?? null) ? $config['api_key'] : '';

            $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'anthropic';
            $model = is_string($config['model'] ?? null) ? $config['model'] : 'claude-haiku-4-5';
            $timeout = is_numeric($config['timeout'] ?? null) ? (int) $config['timeout'] : 15;
            $maxTokens = is_numeric($config['max_tokens'] ?? null) ? (int) $config['max_tokens'] : 512;

            return new LlmGrader(
                driver: $driver,
                model: $model,
                promptTemplate: $prompt,
                apiKey: $apiKey,
                timeout: $timeout,
                maxTokens: $maxTokens,
            );
        });

        $this->app->bind(ConfigDrivenPolicy::class, function (Application $app): ConfigDrivenPolicy {
            $repo = $app->make('config');
            /** @var array<string, class-string> $pipelineMap */
            $pipelineMap = (array) $repo->get('modman.pipeline', []);
            $pipelineKeys = array_keys($pipelineMap);

            $rejectAt = $repo->get('modman.thresholds.auto_reject_at', 0.9);
            $approveBelow = $repo->get('modman.thresholds.auto_approve_below', 0.2);

            return new ConfigDrivenPolicy(
                pipeline: array_map(strval(...), array_keys($pipelineMap)),
                autoRejectAt: is_numeric($rejectAt) ? (float) $rejectAt : 0.9,
                autoApproveBelow: is_numeric($approveBelow) ? (float) $approveBelow : 0.2,
            );
        });

        // task-26: validate the configured policy class at register time so a
        // typo surfaces at boot rather than the first failed pipeline tick.
        $configRepo = $this->app->make('config');
        $configuredPolicy = $configRepo->get('modman.policy', ConfigDrivenPolicy::class);
        $policyClass = is_string($configuredPolicy) ? $configuredPolicy : ConfigDrivenPolicy::class;

        if (! class_exists($policyClass)) {
            throw new InvalidArgumentException(
                "modman.policy: class [{$policyClass}] does not exist."
            );
        }
        if (! is_subclass_of($policyClass, ModerationPolicy::class)) {
            throw new InvalidArgumentException(
                "modman.policy: class [{$policyClass}] must implement ".ModerationPolicy::class.'.'
            );
        }

        $this->app->bind(ModerationPolicy::class, function (Application $app) use ($policyClass): ModerationPolicy {
            /** @var ModerationPolicy $instance */
            $instance = $app->make($policyClass);

            return $instance;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Fail-closed defaults: hosts override these gates to authorize their
        // moderators. Gate::has() ensures we never clobber a host definition.
        if (! Gate::has('modman.view')) {
            Gate::define('modman.view', fn (): bool => false);
        }
        if (! Gate::has('modman.resolve')) {
            Gate::define('modman.resolve', fn (): bool => false);
        }
        if (! Gate::has('modman.reopen')) {
            Gate::define('modman.reopen', fn (): bool => false);
        }

        $config = $this->app->make('config');

        // task-18: surface malformed denylist regex patterns at boot rather
        // than silently misclassifying as "no match" inside the grader.
        $regex = $config->get('modman.graders.denylist.regex', []);
        if (is_array($regex)) {
            foreach ($regex as $pattern) {
                if (! is_string($pattern)) {
                    throw new InvalidArgumentException(
                        'modman.graders.denylist.regex: every entry must be a string.'
                    );
                }
                if (@preg_match($pattern, '') === false) {
                    $error = preg_last_error_msg();
                    throw new InvalidArgumentException(
                        "modman.graders.denylist.regex: invalid pattern [{$pattern}]: {$error}"
                    );
                }
            }
        }

        if ((bool) $config->get('modman.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

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
