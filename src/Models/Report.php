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
use Override;
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

    /** @var list<string> */
    protected $fillable = [
        'reportable_type',
        'reportable_id',
        'reporter_type',
        'reporter_id',
        'reason',
        'state',
        'resolved_at',
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
        DB::transaction(function () use ($moderator, $reason): void {
            $locked = $this->lockSelf();
            $locked->guardTransition(ResolvedApproved::class);

            $locked->writeHumanDecision($moderator, VerdictKind::Approve, $reason);
            $locked->state->transition(new ToResolvedApproved($locked, $moderator, $reason));
            $locked->newQuery()->whereKey($locked->getKey())->update(['resolved_at' => now()]);
        });
        $this->refresh();
        // Defer event dispatch to commit so listeners cannot observe rows that
        // a host's surrounding transaction later rolls back. DB::afterCommit()
        // runs immediately when there is no active transaction.
        DB::afterCommit(fn () => Event::dispatch(new ReportResolved($this, VerdictKind::Approve)));
    }

    public function resolveReject(Model $moderator, ?string $reason = null): void
    {
        DB::transaction(function () use ($moderator, $reason): void {
            $locked = $this->lockSelf();
            $locked->guardTransition(ResolvedRejected::class);

            $locked->writeHumanDecision($moderator, VerdictKind::Reject, $reason);
            $locked->state->transition(new ToResolvedRejected($locked, $moderator, $reason));
            $locked->newQuery()->whereKey($locked->getKey())->update(['resolved_at' => now()]);
        });
        $this->refresh();
        DB::afterCommit(fn () => Event::dispatch(new ReportResolved($this, VerdictKind::Reject)));
    }

    public function reopen(Model $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $reason): void {
            $locked = $this->lockSelf();
            $locked->guardTransition(NeedsHuman::class);

            $locked->state->transition(new Reopen($locked, $actor, $reason));
            $locked->newQuery()->whereKey($locked->getKey())->update(['resolved_at' => null]);
        });
        $this->refresh();
        DB::afterCommit(fn () => Event::dispatch(new ReportReopened($this, $actor, $reason)));
    }

    protected static function newFactory(): ReportFactory
    {
        return ReportFactory::new();
    }

    private function lockSelf(): self
    {
        $locked = self::query()->lockForUpdate()->findOrFail($this->getKey());
        assert($locked instanceof self);

        return $locked;
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

    #[Override]
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'state' => ReportState::class,
        ];
    }
}
