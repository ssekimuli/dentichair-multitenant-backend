<?php

namespace App\Http\Controllers\Api\Insurance;

use App\Http\Controllers\Controller;
use App\Models\InsurancePackageProcedure;
use Illuminate\Http\Request;

class InsurancePackageTreatmentController extends Controller
{
    public function index(Request $request, $packageId)
    {
        $coverages = InsurancePackageProcedure::with('procedure')
            ->where('insurance_package_id', $packageId)
            ->get();
            
        return response()->json(['data' => $coverages]);
    }

    public function store(Request $request, $packageId)
    {
        $data = $request->validate([
            'procedure_id' => 'required|exists:treatments,id',
            'coverage_type' => 'required|in:percentage,fixed_amount',
            'coverage_value' => 'required|numeric|min:0',
            'maximum_covered_amount' => 'nullable|numeric|min:0',
            'frequency_limit' => 'nullable|integer|min:1',
            'waiting_period_days' => 'nullable|integer|min:0',
            'requires_pre_authorization' => 'boolean',
            'exclusion_notes' => 'nullable|string',
            'status' => 'in:active,inactive'
        ]);

        $data['insurance_package_id'] = $packageId;

        // Check if already mapped
        $existing = InsurancePackageProcedure::where('insurance_package_id', $packageId)
            ->where('procedure_id', $data['procedure_id'])
            ->first();

        if ($existing) {
            $existing->update($data);
            $coverage = $existing;
            $message = 'Coverage rule updated';
        } else {
            $coverage = InsurancePackageProcedure::create($data);
            $message = 'Coverage rule added';
        }

        return response()->json(['message' => $message, 'data' => $coverage->load('procedure')]);
    }
    
    public function destroy($packageId, $id)
    {
        InsurancePackageProcedure::where('insurance_package_id', $packageId)
            ->where('id', $id)
            ->delete();
            
        return response()->json(['message' => 'Coverage rule removed']);
    }
}
