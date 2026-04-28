<?php

declare(strict_types=1);

namespace Dynamik\Modman\Transitions;

use Dynamik\Modman\Events\ReportTransitioned;
use Dynamik\Modman\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\ModelStates\Transition;

abstract class LoggedTransition extends Transition
{
    public function __construct(
        protected Report $report,
        protected ?Model $actor = null,
        protected ?string $reason = null,
    ) {}

    protected function fromState(): string
    {
        return (string) $this->report->state;
    }

    abstract protected function toState(): string;

    public function handle(): Report
    {
        $from = $this->fromState();
        $to = $this->toState();

        $rawKey = $this->actor?->getKey();
        $actorId = is_scalar($rawKey) ? (string) $rawKey : null;

        DB::transaction(function () use ($from, $to, $actorId): void {
            $this->report->state = $to;
            $this->report->save();

            DB::table('moderation_transitions')->insert([
                'id' => (string) Str::ulid(),
                'report_id' => $this->report->id,
                'from_state' => $from,
                'to_state' => $to,
                'actor_type' => $this->actor?->getMorphClass(),
                'actor_id' => $actorId,
                'reason' => $this->reason,
                'created_at' => now(),
            ]);
        });

        $fresh = $this->report->fresh() ?? $this->report;
        // Defer to commit so callers wrapping this in a larger transaction
        // cannot have listeners observe state that later rolls back.
        // DB::afterCommit() runs the callback immediately when there is no
        // active transaction, preserving sync behavior in the common case.
        DB::afterCommit(static function () use ($fresh, $from, $to): void {
            Event::dispatch(new ReportTransitioned($fresh, $from, $to));
        });

        return $fresh;
    }
}
