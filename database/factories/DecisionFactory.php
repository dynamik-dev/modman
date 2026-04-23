<?php

declare(strict_types=1);

namespace Dynamik\Modman\Database\Factories;

use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Decision> */
final class DecisionFactory extends Factory
{
    protected $model = Decision::class;

    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'grader' => 'denylist',
            'tier' => 'denylist',
            'verdict' => 'approve',
            'severity' => 0.0,
            'reason' => 'no match',
            'evidence' => [],
        ];
    }
}
