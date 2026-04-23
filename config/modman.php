<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\DenylistGrader;

return [
    'queue' => env('MODMAN_QUEUE', 'modman'),
    'connection' => env('MODMAN_QUEUE_CONNECTION'),

    'pipeline' => [
        'denylist' => DenylistGrader::class,
    ],

    'policy' => 'Dynamik\\Modman\\Policy\\ConfigDrivenPolicy',

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
    ],
];
