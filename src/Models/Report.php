<?php

declare(strict_types=1);

namespace Dynamik\Modman\Models;

use Dynamik\Modman\Database\Factories\ReportFactory;
use Dynamik\Modman\Events\ReportReopened;
use Dynamik\Modman\Events\ReportResolved;
use Dynamik\Modman\States\NeedsHuman;
use Dynamik\Modman\States\ReportState;
use Dynamik\Modman\States\ResolvedApproved;
use Dynamik\Modman\States\ResolvedRejected;
use Dynamik\Modman\Support\Tier;
use Dynamik\Modman\Support\VerdictKind;
use Dynamik\Modman\Transitions\Reopen;
use Dynamik\Modman\Transitions\ToResolvedApproved;
use Dynamik\Modman\Transitions\ToResolvedRejected;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\ModelStates\HasStates;

/**
 * @property string $id
 * @property string $reportable_type
 * @property string $reportable_id
 * @property string|null $reporter_type
 * @property string|null $reporter_id
 * @property string|null $reason
 * @property-read ReportState $state
 * @property-write ReportState|string $state
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use HasStates;
    use HasUlids;

    protected $table = 'reports';

    protected $guarded = [];

    protected $casts = [
        'resolved_at' => 'datetime',
        'state' => ReportState::class,
    ];

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reporter(): MorphTo
    {
        return $this->morphTo();
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    public function resolveApprove(Model $moderator, ?string $reason = null): void
    {
        $this->guardTransition(ResolvedApproved::class);

        DB::transaction(function () use ($moderator, $reason): void {
            $this->writeHumanDecision($moderator, VerdictKind::Approve, $reason);
            $this->state->transition(new ToResolvedApproved($this, $moderator, $reason));
            $this->newQuery()->whereKey($this->getKey())->update(['resolved_at' => now()]);
            $this->refresh();
        });
        Event::dispatch(new ReportResolved($this, VerdictKind::Approve));
    }

    public function resolveReject(Model $moderator, ?string $reason = null): void
    {
        $this->guardTransition(ResolvedRejected::class);

        DB::transaction(function () use ($moderator, $reason): void {
            $this->writeHumanDecision($moderator, VerdictKind::Reject, $reason);
            $this->state->transition(new ToResolvedRejected($this, $moderator, $reason));
            $this->newQuery()->whereKey($this->getKey())->update(['resolved_at' => now()]);
            $this->refresh();
        });
        Event::dispatch(new ReportResolved($this, VerdictKind::Reject));
    }

    public function reopen(Model $actor, ?string $reason = null): void
    {
        $this->guardTransition(NeedsHuman::class);

        DB::transaction(function () use ($actor, $reason): void {
            $this->state->transition(new Reopen($this, $actor, $reason));
            $this->newQuery()->whereKey($this->getKey())->update(['resolved_at' => null]);
            $this->refresh();
        });
        Event::dispatch(new ReportReopened($this, $actor, $reason));
    }

    protected static function newFactory(): ReportFactory
    {
        return ReportFactory::new();
    }

    /**
     * @param  class-string<ReportState>  $targetState
     */
    private function guardTransition(string $targetState): void
    {
        if (! $this->state->canTransitionTo($targetState)) {
            throw CouldNotPerformTransition::notFound(
                (string) $this->state,
                $targetState::getMorphClass(),
                $this,
            );
        }
    }

    private function writeHumanDecision(Model $actor, VerdictKind $kind, ?string $reason): void
    {
        $rawKey = $actor->getKey();
        $actorId = is_scalar($rawKey) ? (string) $rawKey : null;

        Decision::query()->create([
            'report_id' => $this->id,
            'grader' => 'human',
            'tier' => Tier::Human->value,
            'verdict' => $kind->value,
            'severity' => null,
            'reason' => $reason,
            'evidence' => [],
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actorId,
        ]);
    }
}
