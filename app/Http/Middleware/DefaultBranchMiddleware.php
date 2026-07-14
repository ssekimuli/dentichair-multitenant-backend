<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DefaultBranchMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if ($user && $user->branch_id) {
            if (!$request->has('branch_id')) {
                $request->merge(['branch_id' => $user->branch_id]);
            }
            if (!$request->has('branchId')) {
                $request->merge(['branchId' => $user->branch_id]);
            }
        }

        return $next($request);
    }
}
