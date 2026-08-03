<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Patient;

class AllAttachedPoliciesController extends Controller
{
    public function __invoke(Request $request)
    {
        $attached = DB::table('patient_insurance_policies')
            ->join('patients', 'patients.id', '=', 'patient_insurance_policies.patient_id')
            ->join('insurance_policies', 'insurance_policies.id', '=', 'patient_insurance_policies.insurance_policy_id')
            ->join('insurance_packages', 'insurance_packages.id', '=', 'insurance_policies.insurance_package_id')
            ->join('insurance_companies', 'insurance_companies.id', '=', 'insurance_packages.insurance_company_id')
            ->select(
                'patient_insurance_policies.id',
                'patient_insurance_policies.patient_id',
                'patients.first_name',
                'patients.last_name',
                'insurance_companies.name as provider_name',
                'insurance_policies.policy_number',
                'patient_insurance_policies.status'
            )
            ->get();

        $formatted = $attached->map(function ($item) {
            return [
                'id' => $item->id,
                'patient_id' => $item->patient_id,
                'patient' => [
                    'first_name' => $item->first_name,
                    'last_name' => $item->last_name,
                ],
                'provider_name' => $item->provider_name,
                'policy_number' => $item->policy_number,
                'status' => $item->status,
            ];
        });

        return response()->json($formatted);
    }
}
