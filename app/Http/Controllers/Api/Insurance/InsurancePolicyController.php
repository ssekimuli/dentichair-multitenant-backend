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

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:pending,active,suspended,expired,cancelled']);
        $policy = InsurancePolicy::findOrFail($id);
        $policy->update(['status' => $request->status]);
        return response()->json(['message' => 'Status updated']);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'insurance_package_id' => 'required|exists:insurance_packages,id',
            'policy_number' => 'required|string|unique:insurance_policies,policy_number,' . $id,
            'policy_type' => 'in:individual,family,corporate',
            'employer_or_group_name' => 'nullable|string',
            'coverage_start_date' => 'required|date',
            'coverage_end_date' => 'required|date|after:coverage_start_date',
            'total_coverage_limit' => 'nullable|numeric|min:0',
        ]);

        $policy = InsurancePolicy::findOrFail($id);
        
        // Optionally update remaining balance if total coverage limit is updated
        if (isset($data['total_coverage_limit']) && $data['total_coverage_limit'] != $policy->total_coverage_limit) {
            $diff = $data['total_coverage_limit'] - ($policy->total_coverage_limit ?? 0);
            $data['remaining_balance'] = ($policy->remaining_balance ?? 0) + $diff;
        }

        $policy->update($data);
        return response()->json(['message' => 'Policy updated', 'data' => $policy]);
    }

    public function destroy($id)
    {
        $policy = InsurancePolicy::findOrFail($id);
        
        if ($policy->patients()->count() > 0) {
            return response()->json(['message' => 'Cannot delete policy with associated patients'], 400);
        }
        
        $policy->delete();
        return response()->json(['message' => 'Policy deleted']);
    }
}
