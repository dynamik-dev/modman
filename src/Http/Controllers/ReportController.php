<?php

declare(strict_types=1);

namespace Dynamik\Modman\Http\Controllers;

use Dynamik\Modman\Http\Resources\ReportResource;
use Dynamik\Modman\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

final class ReportController extends Controller
{
    public function show(Request $request, Report $report): JsonResponse
    {
        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        // @phpstan-ignore instanceof.alwaysTrue
        if (! $actor instanceof Model) {
            return response()->json(['error' => 'unsupported_identity'], 403);
        }
        if (Gate::forUser($actor)->denies('modman.view', $report)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $report->load('decisions');

        return (new ReportResource($report))->response($request);
    }

    public function resolve(Request $request, Report $report): JsonResponse
    {
        /** @var array{decision: string, reason?: string|null} $data */
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        // Custom guards may return a non-Model Authenticatable; we cannot pass
        // such an identity to the state methods or to the Gate's $report arg.
        // @phpstan-ignore instanceof.alwaysTrue
        if (! $actor instanceof Model) {
            return response()->json(['error' => 'unsupported_identity'], 403);
        }
        // Use Gate::forUser so authorization works for any Authenticatable that
        // does not pull in the Authorizable trait.
        if (Gate::forUser($actor)->denies('modman.resolve', $report)) {
            return response()->json(['error' => 'forbidden'], 403);
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
        if ($actor === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        // @phpstan-ignore instanceof.alwaysTrue
        if (! $actor instanceof Model) {
            return response()->json(['error' => 'unsupported_identity'], 403);
        }
        if (Gate::forUser($actor)->denies('modman.reopen', $report)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $report->reopen($actor, $data['reason'] ?? null);

        $fresh = $report->fresh();
        if ($fresh === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return (new ReportResource($fresh->load('decisions')))->response($request);
    }
}
