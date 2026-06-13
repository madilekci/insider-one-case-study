<?php

namespace App\Http\Controllers;

use App\Services\NotificationTemplateService;
use Illuminate\Http\JsonResponse;

class NotificationTemplateController extends Controller
{
    public function index(NotificationTemplateService $templates): JsonResponse
    {
        return response()->json([
            'data' => $templates->all(),
        ]);
    }
}