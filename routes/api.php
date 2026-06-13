<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::post('/notifications', [NotificationController::class, 'store']);
Route::get('/notifications', [NotificationController::class, 'index']);
Route::post('/notifications/{id}/cancel', [NotificationController::class, 'cancel'])->whereUuid('id');
Route::get('/notifications/{id}', [NotificationController::class, 'show'])->whereUuid('id');
Route::get('/batches/{batchId}', [NotificationController::class, 'batch'])->whereUuid('batchId');
