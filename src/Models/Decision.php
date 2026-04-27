<?php

declare(strict_types=1);

namespace Dynamik\Modman\Models;

use Dynamik\Modman\Database\Factories\DecisionFactory;
use Dynamik\Modman\Support\VerdictKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * `tier` is intentionally a free-form string: custom graders persist their
 * configured key when it is not one of the shipped Tier enum cases, so the
 * audit trail records the grader as it actually appeared in the pipeline.
 *
 * @property string $id
 * @property string $report_id
 * @property string $grader
 * @property string $tier
 * @property VerdictKind $verdict
 * @property float|null $severity
 * @property string|null $reason
 * @property array<string, mixed>|null $evidence
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property Carbon|null $created_at
 */
final class Decision extends Model
{
    /** @use HasFactory<DecisionFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'moderation_decisions';

    /** @var list<string> */
    protected $fillable = [
        'report_id',
        'grader',
        'tier',
        'verdict',
        'severity',
        'reason',
        'evidence',
        'actor_type',
        'actor_id',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): DecisionFactory
    {
        return DecisionFactory::new();
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'verdict' => VerdictKind::class,
            'severity' => 'float',
            'evidence' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
