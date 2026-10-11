<?php

namespace App\Http\Controllers;

use App\Models\MedicalHistory;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MedicalHistoryController extends Controller
{
    /**
     * Display a listing of medical histories for a specific patient.
     * GET /api/patients/{patientId}/medical-histories
     */
    /**
     * Display a listing of all medical histories across all patients with search and filters.
     * GET /api/medical-histories
     */
    public function all(Request $request): JsonResponse
    {
        $query = MedicalHistory::with([
            'patient:id,first_name,last_name,gender,date_of_birth,phone,email,address,branch_id',
            'patient.branch:id,name',
            'doctor:id,name'
        ]);

        // Branch filter
        if ($request->filled('branch_id')) {
            $query->whereHas('patient', function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            });
        }

        // Patient filter
        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        // Date filters
        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        // Clinician filter
        if ($request->filled('doctor_name')) {
            $query->where('doctor_name', 'like', "%{$request->doctor_name}%");
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        // Consent filter
        if ($request->has('consent') && $request->consent !== null && $request->consent !== '') {
            $query->where('consent', filter_var($request->consent, FILTER_VALIDATE_BOOLEAN));
        }

        // Text search (patient name, phone, complaint, diagnosis, notes)
        if ($request->filled('search')) {
            $term = trim($request->search);
            $query->where(function ($q) use ($term) {
                $q->where('chief_complaint', 'like', "%{$term}%")
                  ->orWhere('diagnosis', 'like', "%{$term}%")
                  ->orWhere('history_complaint', 'like', "%{$term}%")
                  ->orWhere('treatment_plan', 'like', "%{$term}%")
                  ->orWhere('treatment_given', 'like', "%{$term}%")
                  ->orWhere('treatment_note', 'like', "%{$term}%")
                  ->orWhere('doctor_name', 'like', "%{$term}%")
                  ->orWhereHas('patient', function ($pq) use ($term) {
                      $pq->where('first_name', 'like', "%{$term}%")
                         ->orWhere('last_name', 'like', "%{$term}%")
                         ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$term}%"])
                         ->orWhere('phone', 'like', "%{$term}%")
                         ->orWhere('email', 'like', "%{$term}%");
                  });
            });
        }

        $query->orderBy('date', 'desc')->orderBy('created_at', 'desc');

        if ($request->input('per_page') === 'all' || $request->boolean('all')) {
            $items = $query->get();
            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'total' => $items->count(),
                ]
            ]);
        }

        $perPage = (int) $request->input('per_page', 20);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Clinical records retrieved successfully.',
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ]
        ]);
    }

    public function index(string|int $patientId): JsonResponse
    {
        $histories = MedicalHistory::where('patient_id', $patientId)
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $histories,
        ]);
    }

    /**
     * Store a newly created medical history for a patient.
     * POST /api/patients/{patientId}/medical-histories
     */
    public function store(Request $request, string|int $patientId): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'chief_complaint' => 'required|string',
            'history_complaint' => 'nullable|string',
            'extra_oral_exam' => 'nullable|string',
            'intra_oral_exam' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'treatment_plan' => 'nullable|string',
            'treatment_given' => 'nullable|string',
            'consent' => 'nullable|boolean',
            'consent_notes' => 'nullable|string',
            'treatment_note' => 'nullable|string',
            'doctor_name' => 'nullable|string|max:255',
            'doctor_id' => 'nullable|integer',
        ]);

        $validated['patient_id'] = $patientId;
        $validated['date'] = $validated['date'] ?? now()->toDateString();
        $validated['consent'] = $request->has('consent') ? (bool)$request->input('consent') : true;

        $record = MedicalHistory::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Medical history created successfully.',
            'data' => $record,
        ], 201);
    }

    /**
     * Display the specified medical history.
     * GET /api/medical-histories/{id}
     */
    public function show(string|int $id): JsonResponse
    {
        $record = MedicalHistory::with('patient')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $record,
        ]);
    }

    /**
     * Update the specified medical history.
     * PUT /api/medical-histories/{id}
     */
    public function update(Request $request, string|int $id): JsonResponse
    {
        $record = MedicalHistory::findOrFail($id);

        $validated = $request->validate([
            'date' => 'nullable|date',
            'chief_complaint' => 'sometimes|required|string',
            'history_complaint' => 'nullable|string',
            'extra_oral_exam' => 'nullable|string',
            'intra_oral_exam' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'treatment_plan' => 'nullable|string',
            'treatment_given' => 'nullable|string',
            'consent' => 'nullable|boolean',
            'consent_notes' => 'nullable|string',
            'treatment_note' => 'nullable|string',
            'doctor_name' => 'nullable|string|max:255',
            'doctor_id' => 'nullable|integer',
        ]);

        $record->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Medical history updated successfully.',
            'data' => $record,
        ]);
    }

    /**
     * Remove the specified medical history from storage.
     * DELETE /api/medical-histories/{id}
     */
    public function destroy(string|int $id): JsonResponse
    {
        $record = MedicalHistory::findOrFail($id);
        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Medical history deleted successfully.',
        ]);
    }

    /**
     * Display a printable HTML view of the medical history case sheet.
     * GET /medical-histories/{id}/print
     */
    public function print(string|int $id)
    {
        $record = MedicalHistory::with(['patient.branch.setting', 'doctor'])->findOrFail($id);
        $patient = $record->patient;
        $branch = $patient?->branch ?? \App\Models\Branch::first();
        $branchSetting = $branch?->setting;
        $business = \App\Models\Business::first();
        $globalSetting = \App\Models\GlobalSetting::first();

        // 1. Facility / Clinic Name (from Business, GlobalSetting, BranchSetting, Branch, env)
        $clinicName = !empty($business?->name) ? $business->name : null;
        if (!$clinicName && !empty($globalSetting?->clinic_name)) {
            $clinicName = $globalSetting->clinic_name;
        }
        if (!$clinicName && !empty($branchSetting?->clinic_name)) {
            $clinicName = $branchSetting->clinic_name;
        }
        if (!$clinicName && !empty($branch?->name)) {
            $clinicName = $branch->name;
        }
        if (!$clinicName) {
            $clinicName = env('CLINIC_NAME', env('BUSINESS_NAME', env('FACILITY_NAME', config('app.name') !== 'Laravel' ? config('app.name') : 'Dental & Oral Healthcare Clinic')));
        }

        // 2. Facility Subtitle / Tagline / Details
        $clinicDetails = !empty($business?->details) ? $business->details : null;
        if (!$clinicDetails) {
            $clinicDetails = env('CLINIC_DETAILS', env('FACILITY_DETAILS', env('CLINIC_TAGLINE', 'Comprehensive Oral Healthcare, Dental Surgery & Preventive Care')));
        }

        // 3. Facility Address
        $clinicAddress = !empty($branch?->address) ? $branch->address : null;
        if (!$clinicAddress && !empty($globalSetting?->address)) {
            $clinicAddress = $globalSetting->address;
        }
        if (!$clinicAddress) {
            $clinicAddress = env('CLINIC_ADDRESS', env('FACILITY_ADDRESS', ''));
        }

        // 4. Facility Phone Contact
        $clinicPhone = !empty($branch?->phone) ? $branch->phone : null;
        if (!$clinicPhone) {
            $clinicPhone = env('CLINIC_PHONE', env('FACILITY_PHONE', env('BUSINESS_PHONE', env('APP_PHONE', ''))));
        }

        // 5. Facility Email
        $clinicEmail = !empty($business?->business_email) ? $business->business_email : null;
        if (!$clinicEmail && !empty($branch?->email)) {
            $clinicEmail = $branch->email;
        }
        if (!$clinicEmail) {
            $clinicEmail = env('CLINIC_EMAIL', env('FACILITY_EMAIL', env('BUSINESS_EMAIL', config('mail.from.address', ''))));
        }

        $branchName = $branch?->name ?? '';

        return view('medical-histories.print', compact(
            'record',
            'patient',
            'branch',
            'clinicName',
            'clinicDetails',
            'clinicAddress',
            'clinicPhone',
            'clinicEmail',
            'branchName'
        ));
    }

}
