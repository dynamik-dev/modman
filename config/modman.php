<?php

declare(strict_types=1);

return [
    'queue' => env('MODMAN_QUEUE', 'modman'),
    'connection' => env('MODMAN_QUEUE_CONNECTION'),
    'pipeline' => [],
    'policy' => null,
    'thresholds' => [
        'auto_reject_at' => 0.9,
        'auto_approve_below' => 0.2,
    ],
    'graders' => [],
];
