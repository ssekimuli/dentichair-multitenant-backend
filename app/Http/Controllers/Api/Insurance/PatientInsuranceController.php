<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientInsurancePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class PatientInsuranceController extends Controller
{
    public function index(Patient $patient)
    {
        $policies = $patient->insurancePolicies()->with('package')->get();
        return response()->json(['data' => $policies]);
    }

    public function attach(Request $request, Patient $patient)
    {
        $data = $request->validate([
            'insurance_policy_id' => 'required|exists:insurance_policies,id',
            'relationship' => 'required|string',
            'membership_number' => 'nullable|string',
            'coverage_start_date' => 'nullable|date',
            'coverage_end_date' => 'nullable|date',
            'is_primary' => 'boolean',
        ]);
        
        DB::transaction(function () use ($patient, $data) {
            if (!empty($data['is_primary'])) {
                DB::table('patient_insurance_policies')
                    ->where('patient_id', $patient->id)
                    ->update(['is_primary' => false]);
            }

            $patient->insurancePolicies()->attach($data['insurance_policy_id'], [
                'membership_number' => $data['membership_number'] ?? null,
                'relationship' => $data['relationship'],
                'coverage_start_date' => $data['coverage_start_date'] ?? null,
                'coverage_end_date' => $data['coverage_end_date'] ?? null,
                'is_primary' => $data['is_primary'] ?? false,
                'verification_status' => 'pending',
                'status' => 'active',
            ]);
        });

        return response()->json(['message' => 'Policy attached successfully.']);
    }

    public function setPrimary(Request $request, Patient $patient, $pivotId)
    {
        DB::transaction(function () use ($patient, $pivotId) {
            DB::table('patient_insurance_policies')
                ->where('patient_id', $patient->id)
                ->update(['is_primary' => false]);

            DB::table('patient_insurance_policies')
                ->where('id', $pivotId)
                ->update(['is_primary' => true]);
        });

        return response()->json(['message' => 'Primary policy updated.']);
    }
}
