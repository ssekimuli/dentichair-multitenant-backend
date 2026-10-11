<?php

namespace App\Http\Controllers;

use App\Models\XrayRequest;
use App\Models\Patient;
use App\Models\Branch;
use App\Models\Business;
use App\Models\GlobalSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class XrayRequestController extends Controller
{
    /**
     * Display a listing of all X-ray / Radiology requests with search & filters.
     * GET /api/xray-requests
     */
    public function index(Request $request): JsonResponse
    {
        $query = XrayRequest::with([
            'patient:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patient.branch:id,name',
            'branch:id,name',
            'doctor:id,name'
        ]);

        // Branch filter
        if ($request->filled('branch_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id)
                  ->orWhereHas('patient', function ($pq) use ($request) {
                      $pq->where('branch_id', $request->branch_id);
                  });
            });
        }

        // Patient filter
        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Facility filter
        if ($request->filled('facility') && $request->facility !== 'all') {
            $query->where('facility', 'like', "%{$request->facility}%");
        }

        // X-ray type filter
        if ($request->filled('xray_type') && $request->xray_type !== 'all') {
            $query->where('xray_type', 'like', "%{$request->xray_type}%");
        }

        // Date filters
        if ($request->filled('date')) {
            $query->whereDate('request_date', $request->date);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('request_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('request_date', '<=', $request->date_to);
        }

        // Clinician filter
        if ($request->filled('doctor_name')) {
            $query->where('doctor_name', 'like', "%{$request->doctor_name}%");
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        // Multi-field text search
        if ($request->filled('search')) {
            $term = trim($request->search);
            $query->where(function ($q) use ($term) {
                $q->where('facility', 'like', "%{$term}%")
                  ->orWhere('xray_type', 'like', "%{$term}%")
                  ->orWhere('tooth_region', 'like', "%{$term}%")
                  ->orWhere('clinical_notes', 'like', "%{$term}%")
                  ->orWhere('doctor_name', 'like', "%{$term}%")
                  ->orWhereHas('patient', function ($pq) use ($term) {
                      $pq->where('first_name', 'like', "%{$term}%")
                         ->orWhere('last_name', 'like', "%{$term}%")
                         ->orWhere('phone', 'like', "%{$term}%")
                         ->orWhere('email', 'like', "%{$term}%")
                         ->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$term}%");
                  });
            });
        }

        // Sort by date desc
        $query->orderBy('request_date', 'desc')->orderBy('id', 'desc');

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $query->paginate($perPage);

        return response()->json($paginated);
    }

    /**
     * Store a newly created X-ray request in storage.
     * POST /api/xray-requests
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => 'required|exists:patients,id',
            'facility' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'xray_type' => 'nullable|string|max:255',
            'tooth_region' => 'nullable|string|max:255',
            'clinical_notes' => 'nullable|string',
            'doctor_name' => 'nullable|string|max:255',
            'doctor_id' => 'nullable|integer',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|string|in:pending,completed,cancelled',
            'request_date' => 'nullable|date',
            'completed_date' => 'nullable|date',
            'report_findings' => 'nullable|string',
        ]);

        if (empty($validated['request_date'])) {
            $validated['request_date'] = now()->toDateString();
        }
        if (empty($validated['status'])) {
            $validated['status'] = 'pending';
        }

        // Auto-assign branch from patient if not supplied
        if (empty($validated['branch_id'])) {
            $patient = Patient::find($validated['patient_id']);
            $validated['branch_id'] = $patient?->branch_id;
        }

        $xrayRequest = XrayRequest::create($validated);
        $xrayRequest->load([
            'patient:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patient.branch:id,name',
            'branch:id,name'
        ]);

        return response()->json([
            'message' => 'X-ray request created successfully',
            'data' => $xrayRequest
        ], 201);
    }

    /**
     * Display the specified X-ray request.
     * GET /api/xray-requests/{id}
     */
    public function show($id): JsonResponse
    {
        $xrayRequest = XrayRequest::with([
            'patient:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patient.branch:id,name',
            'branch:id,name',
            'doctor:id,name'
        ])->findOrFail($id);

        return response()->json([
            'data' => $xrayRequest
        ]);
    }

    /**
     * Update the specified X-ray request in storage.
     * PUT /api/xray-requests/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $xrayRequest = XrayRequest::findOrFail($id);

        $validated = $request->validate([
            'patient_id' => 'sometimes|required|exists:patients,id',
            'facility' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'xray_type' => 'nullable|string|max:255',
            'tooth_region' => 'nullable|string|max:255',
            'clinical_notes' => 'nullable|string',
            'doctor_name' => 'nullable|string|max:255',
            'doctor_id' => 'nullable|integer',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|string|in:pending,completed,cancelled',
            'request_date' => 'nullable|date',
            'completed_date' => 'nullable|date',
            'report_findings' => 'nullable|string',
        ]);

        $xrayRequest->update($validated);
        $xrayRequest->load([
            'patient:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patient.branch:id,name',
            'branch:id,name'
        ]);

        return response()->json([
            'message' => 'X-ray request updated successfully',
            'data' => $xrayRequest
        ]);
    }

    /**
     * Remove the specified X-ray request from storage.
     * DELETE /api/xray-requests/{id}
     */
    public function destroy($id): JsonResponse
    {
        $xrayRequest = XrayRequest::findOrFail($id);
        $xrayRequest->delete();

        return response()->json([
            'message' => 'X-ray request deleted successfully'
        ]);
    }

    /**
     * Print Requisition Form for the X-ray request.
     * GET /api/xray-requests/{id}/print
     */
    public function print($id)
    {
        $xrayRequest = XrayRequest::with([
            'patient:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patient.branch:id,name',
            'branch:id,name',
            'doctor:id,name'
        ])->findOrFail($id);

        $clinicName = config('app.name', 'DENTAL CLINIC');
        $clinicDetails = 'Oral & Maxillofacial Diagnostic Center';
        $clinicAddress = '';
        $clinicPhone = '';
        $clinicEmail = '';

        if (Schema::hasTable('businesses')) {
            $business = Business::first();
            if ($business) {
                $clinicName = $business->name ?: $clinicName;
                $clinicDetails = $business->tagline ?: $clinicDetails;
                $clinicAddress = $business->address ?: $clinicAddress;
                $clinicPhone = $business->phone ?: $clinicPhone;
                $clinicEmail = $business->email ?: $clinicEmail;
            }
        }

        if ($xrayRequest->branch) {
            $clinicName = $xrayRequest->branch->name ?: $clinicName;
            $clinicAddress = $xrayRequest->branch->address ?: $clinicAddress;
            $clinicPhone = $xrayRequest->branch->phone ?: $clinicPhone;
        }

        return view('xray-requests.print', compact(
            'xrayRequest',
            'clinicName',
            'clinicDetails',
            'clinicAddress',
            'clinicPhone',
            'clinicEmail'
        ));
    }
}
