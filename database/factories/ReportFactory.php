<?php

declare(strict_types=1);

namespace Dynamik\Modman\Database\Factories;

use Dynamik\Modman\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Report> */
final class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'reportable_type' => 'Dynamik\\Modman\\Tests\\Fixtures\\TestReportable',
            'reportable_id' => (string) Str::ulid(),
            'reason' => 'spam',
            'state' => 'pending',
        ];
    }
}
