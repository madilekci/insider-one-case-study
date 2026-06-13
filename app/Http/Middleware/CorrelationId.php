<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header(self::HEADER) ?: (string) Str::uuid();

        $request->headers->set(self::HEADER, $correlationId);

        Log::withContext([
            'correlation_id' => $correlationId,
        ]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
