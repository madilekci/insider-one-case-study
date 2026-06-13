<?php

use App\Http\Controllers\DashboardController;
use App\Http\Middleware\LocalOnly;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use App\Services\Observability\Metrics;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function (): JsonResponse {
    return response()->json([
        'status' => 'ok',
    ]);
});

Route::get('/metrics', function (Metrics $metrics): JsonResponse {
    return response()->json($metrics->snapshot());
});

Route::middleware(LocalOnly::class)->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);
    Route::get('/dashboard/logs', [DashboardController::class, 'logs']);
    Route::get('/dashboard/notifications', [DashboardController::class, 'notifications']);
    Route::get('/dashboard/health', [DashboardController::class, 'health']);
    Route::get('/dashboard/demo/status', [DashboardController::class, 'demoTrafficStatus']);
    Route::post('/dashboard/demo/start', [DashboardController::class, 'startDemoTraffic']);
    Route::delete('/dashboard/demo/clear', [DashboardController::class, 'clearDemoTraffic']);
    Route::post('/dashboard/tests/run', [DashboardController::class, 'runTests']);
    Route::get('/dashboard/tests/{runId}', [DashboardController::class, 'testStatus']);
});
