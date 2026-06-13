<?php

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
