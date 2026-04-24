<?php

declare(strict_types=1);

use Dynamik\Modman\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('modman')->middleware('api')->group(function (): void {
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('modman.reports.show');
    Route::post('reports/{report}/resolve', [ReportController::class, 'resolve'])->name('modman.reports.resolve');
    Route::post('reports/{report}/reopen', [ReportController::class, 'reopen'])->name('modman.reports.reopen');
});
