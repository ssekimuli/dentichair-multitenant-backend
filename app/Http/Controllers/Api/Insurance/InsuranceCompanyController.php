<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use App\Models\InsuranceCompany;
use Illuminate\Http\Request;

class InsuranceCompanyController extends Controller
{
    public function index()
    {
        $companies = InsuranceCompany::withCount('packages')->get();
        return response()->json(['data' => $companies]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|unique:insurance_companies',
            'name' => 'required|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'status' => 'in:active,inactive'
        ]);

        $company = InsuranceCompany::create($data);
        return response()->json(['message' => 'Company created', 'data' => $company]);
    }
}
