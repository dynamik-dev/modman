<?php

declare(strict_types=1);

use Dynamik\Modman\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

$middleware = config('modman.routes.middleware', ['api', 'auth']);
if (! is_array($middleware)) {
    $middleware = ['api', 'auth'];
}

Route::prefix('modman')->middleware($middleware)->group(function (): void {
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('modman.reports.show');
    Route::post('reports/{report}/resolve', [ReportController::class, 'resolve'])->name('modman.reports.resolve');
    Route::post('reports/{report}/reopen', [ReportController::class, 'reopen'])->name('modman.reports.reopen');
});
