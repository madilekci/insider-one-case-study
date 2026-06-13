<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isLocal()) {
            abort(403, 'This page is only available in the local environment.');
        }

        return $next($request);
    }
}
