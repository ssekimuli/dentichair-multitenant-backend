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
            $data = $request->all();
            if (is_array($data) && array_is_list($data)) {
                $modified = false;
                foreach ($data as $key => $item) {
                    if (is_array($item)) {
                        if (!isset($item['branch_id']) && !isset($item['branchId'])) {
                            $data[$key]['branch_id'] = $user->branch_id;
                            $data[$key]['branchId'] = $user->branch_id;
                            $modified = true;
                        }
                    }
                }
                if ($modified) {
                    $request->replace($data);
                }
            } else {
                if (!$request->has('branch_id')) {
                    $request->merge(['branch_id' => $user->branch_id]);
                }
                if (!$request->has('branchId')) {
                    $request->merge(['branchId' => $user->branch_id]);
                }
            }
        }

        return $next($request);
    }
}
