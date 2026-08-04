<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use App\Models\InsuranceCompany;
use Illuminate\Http\Request;

class InsuranceCompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = InsuranceCompany::withCount('packages');
        
        if ($request->has('search') && $request->search != '') {
            $searchTerm = '%' . $request->search . '%';
            $query->where('name', 'like', $searchTerm)
                  ->orWhere('code', 'like', $searchTerm);
        }

        $companies = $query->get();
        return response()->json(['data' => $companies]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'required|string|unique:insurance_companies',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'status' => 'in:active,inactive'
        ]);

        $company = InsuranceCompany::create($data);
        
        return response()->json(['message' => 'Company added', 'data' => $company]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:active,inactive']);
        $company = InsuranceCompany::findOrFail($id);
        $company->update(['status' => $request->status]);
        return response()->json(['message' => 'Status updated']);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'required|string|unique:insurance_companies,code,' . $id,
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
        ]);

        $company = InsuranceCompany::findOrFail($id);
        $company->update($data);
        return response()->json(['message' => 'Company updated', 'data' => $company]);
    }

    public function destroy($id)
    {
        $company = InsuranceCompany::findOrFail($id);
        
        // Prevent deletion if there are packages
        if ($company->packages()->count() > 0) {
            return response()->json(['message' => 'Cannot delete company with associated packages'], 400);
        }
        
        $company->delete();
        return response()->json(['message' => 'Company deleted']);
    }
}
