<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $query = \App\Models\ActivityLog::with('user');
        
        if ($request->has('action') && $request->input('action') !== 'all') {
            $query->where('action', $request->input('action'));
        }
        if ($request->has('category') && $request->input('category') !== 'all') {
            $query->where('action', $request->input('category'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('metadata', 'like', "%{$search}%");
            });
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        if ($request->filled('user_id') && $request->input('user_id') !== 'all') {
            $query->where('user_id', $request->input('user_id'));
        }
        
        $logs = $query->latest()->paginate($request->input('per_page', 20));
        
        $logs->getCollection()->transform(function($log) {
            $metadata = $log->metadata ?? [];
            $log->user_name = $metadata['user_name'] ?? 'System Action';
            return $log;
        });

        return response()->json($logs);
    }
}
