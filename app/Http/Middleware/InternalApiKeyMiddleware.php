<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InternalApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $expectedKey = env('INTERNAL_API_KEY');

        if (!$expectedKey) {
            // If no key is configured, allow (for local dev without docker)
            return $next($request);
        }

        $providedKey = $request->header('X-Internal-Key');

        if (!$providedKey || !hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Direct access to this service is not allowed. Use the API gateway.'
            ], 403);
        }

        return $next($request);
    }
}
