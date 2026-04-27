<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\DenylistGrader;
use Dynamik\Modman\Graders\LlmGrader;
use Dynamik\Modman\Policy\ConfigDrivenPolicy;

return [
    'queue' => env('MODMAN_QUEUE', 'modman'),
    'connection' => env('MODMAN_QUEUE_CONNECTION'),

    // The shipped /modman/reports/* routes return moderator-only data (free-text
    // reason, LLM evidence, full decision history). They default to the auth
    // middleware. Disable the group entirely or override the middleware stack
    // when wiring host authorization.
    'routes' => [
        'enabled' => true,
        'middleware' => ['api', 'auth'],
    ],

    'pipeline' => [
        'denylist' => DenylistGrader::class,
        'llm' => LlmGrader::class,
    ],

    'policy' => ConfigDrivenPolicy::class,

    'thresholds' => [
        'auto_reject_at' => 0.9,
        'auto_approve_below' => 0.2,
    ],

    'graders' => [
        'denylist' => [
            'words' => [],
            'words_path' => resource_path('modman/denylist.txt'),
            'regex' => [],
            'case_sensitive' => false,
        ],
        'llm' => [
            'driver' => env('MODMAN_LLM_DRIVER', 'anthropic'),
            'model' => env('MODMAN_LLM_MODEL', 'claude-haiku-4-5'),
            'prompt' => resource_path('modman/prompts/grader.md'),
            'max_tokens' => 512,
            'timeout' => 15,
            'api_key' => env('MODMAN_LLM_API_KEY'),
        ],
    ],
];
