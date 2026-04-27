<?php

declare(strict_types=1);

namespace Dynamik\Modman\Http\Resources;

use Dynamik\Modman\Models\Decision;
use Dynamik\Modman\Models\Report;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/** @property Report $resource */
final class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var iterable<int, Decision> $decisions */
        $decisions = $this->resource->decisions;

        $mapped = [];
        foreach ($decisions as $d) {
            $mapped[] = [
                'grader' => $d->grader,
                'tier' => $d->tier,
                'verdict' => $d->verdict,
                'severity' => $d->severity,
                'reason' => $d->reason,
                'created_at' => $d->created_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $this->resource->id,
            'reportable' => [
                'type' => $this->morphAlias($this->resource->reportable_type),
                'id' => $this->resource->reportable_id,
            ],
            'reason' => $this->resource->reason,
            'state' => $this->resource->state->getValue(),
            'resolved_at' => $this->resource->resolved_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'decisions' => $mapped,
        ];
    }

    /**
     * Resolve a morph alias from the registered morph map; fall back to the FQCN
     * when the host has not registered an alias for the class.
     */
    private function morphAlias(string $class): string
    {
        $alias = array_search($class, Relation::morphMap(), true);

        return is_string($alias) ? $alias : $class;
    }
}
