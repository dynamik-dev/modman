<?php

declare(strict_types=1);

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Pipeline\Orchestrator;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Tests\Fixtures\TestReportable;
use Illuminate\Support\Facades\Queue;

// task-6: a consumer-supplied grader keyed 'my_grader' must not record
// tier='hosted_classifier' in moderation_decisions. Unknown keys now record
// the grader key itself as the tier label.
it('records the grader key as the tier when no built-in tier mapping exists', function (): void {
    Queue::fake();

    $custom = new class implements Grader
    {
        public function key(): string
        {
            return 'my_grader';
        }

        public function supports(ModerationContent $content): bool
        {
            return true;
        }

        public function grade(ModerationContent $content, Report $report): Verdict
        {
            return new Verdict(VerdictKind::Approve, 0.0, 'fine');
        }
    };

    config()->set('modman.pipeline', ['my_grader' => $custom::class]);
    app()->instance($custom::class, $custom);

    $reportable = TestReportable::create(['body' => 'hello']);
    $report = $reportable->report(null, 'spam');

    app(Orchestrator::class)->runNext($report->fresh());

    $decision = Decision::query()
        ->where('report_id', $report->id)
        ->where('grader', 'my_grader')
        ->first();

    expect($decision)->not->toBeNull();
    expect($decision->tier)->toBe('my_grader');
});
