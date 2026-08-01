<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PatientInsuranceController extends Controller
{
    public function index()
    {
        return response()->json(\App\Models\PatientInsurance::with('patient')->get());
    }

    public function getByPatient($patientId)
    {
        $insurances = \App\Models\PatientInsurance::where('patient_id', $patientId)->get();
        return response()->json($insurances);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_id' => 'required|exists:patients,id',
            'provider_name' => 'required|string|max:255',
            'policy_number' => 'required|string|max:255',
            'group_number' => 'nullable|string|max:255',
            'plan_type' => 'nullable|string|max:255',
            'coverage_start' => 'nullable|date',
            'coverage_end' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $insurance = \App\Models\PatientInsurance::create($validated);
        return response()->json($insurance, 201);
    }

    public function show($id)
    {
        $insurance = \App\Models\PatientInsurance::with('patient', 'claims')->findOrFail($id);
        return response()->json($insurance);
    }

    public function update(Request $request, $id)
    {
        $insurance = \App\Models\PatientInsurance::findOrFail($id);
        
        $validated = $request->validate([
            'provider_name' => 'sometimes|string|max:255',
            'policy_number' => 'sometimes|string|max:255',
            'group_number' => 'nullable|string|max:255',
            'plan_type' => 'nullable|string|max:255',
            'coverage_start' => 'nullable|date',
            'coverage_end' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $insurance->update($validated);
        return response()->json($insurance);
    }

    public function destroy($id)
    {
        \App\Models\PatientInsurance::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
