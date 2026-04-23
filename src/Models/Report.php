<?php

declare(strict_types=1);

namespace Dynamik\Modman\Models;

use Dynamik\Modman\Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $reportable_type
 * @property string $reportable_id
 * @property string|null $reporter_type
 * @property string|null $reporter_id
 * @property string|null $reason
 * @property string $state
 * @property Carbon|null $resolved_at
 */
final class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'reports';

    protected $guarded = [];

    protected $casts = [
        'resolved_at' => 'datetime',
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

    protected static function newFactory(): ReportFactory
    {
        return ReportFactory::new();
    }
}
