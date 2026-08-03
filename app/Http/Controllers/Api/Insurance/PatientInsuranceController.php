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
            'subscriber_id' => 'required|string',
            'group_number' => 'nullable|string',
            'relationship_to_subscriber' => 'required|in:self,spouse,child,other',
            'subscriber_name' => 'nullable|string',
            'is_primary' => 'boolean',
        ]);
        
        DB::transaction(function () use ($patient, $data) {
            if (!empty($data['is_primary'])) {
                DB::table('patient_insurance_policies')
                    ->where('patient_id', $patient->id)
                    ->update(['is_primary' => false]);
            }

            DB::table('patient_insurance_policies')->insert([
                'patient_id' => $patient->id,
                'insurance_policy_id' => $data['insurance_policy_id'],
                'subscriber_id' => $data['subscriber_id'],
                'group_number' => $data['group_number'] ?? null,
                'relationship_to_subscriber' => $data['relationship_to_subscriber'],
                'subscriber_name' => $data['subscriber_name'] ?? null,
                'is_primary' => $data['is_primary'] ?? false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now()
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
