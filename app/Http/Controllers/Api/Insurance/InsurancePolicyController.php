<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use App\Models\InsurancePolicy;
use Illuminate\Http\Request;

class InsurancePolicyController extends Controller
{
    public function index(Request $request)
    {
        $query = InsurancePolicy::with('package');
        if ($request->has('package_id')) {
            $query->where('insurance_package_id', $request->package_id);
        }
        $policies = $query->get();
        return response()->json(['data' => $policies]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'insurance_package_id' => 'required|exists:insurance_packages,id',
            'policy_number' => 'required|string|unique:insurance_policies',
            'policy_type' => 'in:individual,family,corporate',
            'employer_or_group_name' => 'nullable|string',
            'coverage_start_date' => 'required|date',
            'coverage_end_date' => 'required|date|after:coverage_start_date',
            'total_coverage_limit' => 'nullable|numeric|min:0',
            'status' => 'in:pending,active,suspended,expired,cancelled'
        ]);

        $data['remaining_balance'] = $data['total_coverage_limit'] ?? null;
        $policy = InsurancePolicy::create($data);
        
        return response()->json(['message' => 'Policy created', 'data' => $policy]);
    }
}
