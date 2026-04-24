<?php

declare(strict_types=1);

namespace Dynamik\Modman\Http\Controllers;

use Dynamik\Modman\Http\Resources\ReportResource;
use Dynamik\Modman\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ReportController extends Controller
{
    public function show(Report $report): ReportResource
    {
        $report->load('decisions');

        return new ReportResource($report);
    }

    public function resolve(Request $request, Report $report): JsonResponse
    {
        /** @var array{decision: string, reason?: string|null} $data */
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $request->user();
        if (! $actor instanceof Model) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if ($data['decision'] === 'approve') {
            $report->resolveApprove($actor, $data['reason'] ?? null);
        } else {
            $report->resolveReject($actor, $data['reason'] ?? null);
        }

        $fresh = $report->fresh();
        if ($fresh === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return (new ReportResource($fresh->load('decisions')))->response($request);
    }

    public function reopen(Request $request, Report $report): JsonResponse
    {
        /** @var array{reason?: string|null} $data */
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $request->user();
        if (! $actor instanceof Model) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $report->reopen($actor, $data['reason'] ?? null);

        $fresh = $report->fresh();
        if ($fresh === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return (new ReportResource($fresh->load('decisions')))->response($request);
    }
}
