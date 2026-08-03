<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;

class InsurancePackageController extends Controller
{
    public function index(Request $request)
    {
        $query = Package::query();
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

        $package = Package::create($data);
        return response()->json(['message' => 'Package created', 'data' => $package]);
    }
}
