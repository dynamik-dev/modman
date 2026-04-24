<?php

declare(strict_types=1);

namespace Dynamik\Modman\Models;

use Dynamik\Modman\Database\Factories\DecisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $report_id
 * @property string $grader
 * @property string $tier
 * @property string $verdict
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

    protected $guarded = [];

    protected $casts = [
        'severity' => 'float',
        'evidence' => 'array',
        'created_at' => 'datetime',
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
}
