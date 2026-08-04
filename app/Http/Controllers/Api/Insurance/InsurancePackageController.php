<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use App\Models\InsurancePackage;
use Illuminate\Http\Request;

class InsurancePackageController extends Controller
{
    public function index(Request $request)
    {
        $query = InsurancePackage::query();
        if ($request->has('company_id')) {
            $query->where('insurance_company_id', $request->company_id);
        }
        $packages = $query->get();
        return response()->json(['data' => $packages]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'insurance_company_id' => 'required|exists:insurance_companies,id',
            'name' => 'required|string',
            'code' => 'required|string',
            'description' => 'nullable|string',
            'default_coverage_percentage' => 'nullable|numeric|min:0|max:100',
            'default_annual_limit' => 'nullable|numeric|min:0',
            'requires_pre_authorization' => 'boolean',
            'status' => 'in:active,inactive'
        ]);

        $package = InsurancePackage::create($data);
        return response()->json(['message' => 'Package created', 'data' => $package]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:active,inactive']);
        $package = InsurancePackage::findOrFail($id);
        $package->update(['status' => $request->status]);
        return response()->json(['message' => 'Status updated']);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'insurance_company_id' => 'required|exists:insurance_companies,id',
            'name' => 'required|string',
            'code' => 'required|string',
            'default_coverage_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $package = InsurancePackage::findOrFail($id);
        $package->update($data);
        return response()->json(['message' => 'Package updated', 'data' => $package]);
    }

    public function destroy($id)
    {
        $package = InsurancePackage::findOrFail($id);
        
        if ($package->policies()->count() > 0) {
            return response()->json(['message' => 'Cannot delete package with associated policies'], 400);
        }
        
        $package->delete();
        return response()->json(['message' => 'Package deleted']);
    }
}
