<?php

declare(strict_types=1);

use Dynamik\Modman\Graders\DenylistGrader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;

it('resolves the grader with config-driven words', function (): void {
    config()->set('modman.graders.denylist.words', ['hate']);
    config()->set('modman.graders.denylist.words_path', null);

    $grader = app(DenylistGrader::class);
    $verdict = $grader->grade(
        ModerationContent::make()->withText('I hate this'),
        Report::factory()->make(),
    );

    expect($verdict->kind->value)->toBe('reject');
});
