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
         $appKey = $request->input('app_key') ?? $request->header('X-App-key');

        if (!$appKey) {
            return response()->json(['success' => false, 'message' => 'appKey is required'], 401);
        }

        $envAppKey = env('SYSTEM_KEY');

        if ($appKey !== $envAppKey) {
            return response()->json(['success' => false, 'message' => 'Invalid appKey'], 401);
        }

        // Optional: Attach client to request so it can be used in the controller if needed
        $request->merge(['appkey' => $appKey]);
        return $next($request);
    }
}
