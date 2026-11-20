<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAppKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appKey = $request->header('X-App-Key');

        if (!$appKey) {
            return response()->json(['success' => false, 'message' => 'X-App-Key header is required'], 401);
        }

        $envAppKey = config('app.system_key');

        if (!is_string($envAppKey) || $envAppKey === '' || !hash_equals($envAppKey, $appKey)) {
            return response()->json(['success' => false, 'message' => 'Invalid appKey'], 401);
        }

        return $next($request);
    }
}
