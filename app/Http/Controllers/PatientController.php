<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\DentalRecord;
use App\Models\TreatmentPlan;
use App\Models\PatientImage;
use App\Models\PeriodontalRecord;
use App\Models\SoftTissueRecord;
use App\Models\OrthodonticRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        $query = Patient::with([
            'dentalRecords', 
            'treatmentPlans', 
            'images', 
            'periodontalRecords',
            'softTissueRecords',
            'orthodonticRecords'
        ]);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $patients = $query->get();

        return response()->json($patients);
    }

    public function show($id)
    {
        $patient = Patient::with([
            'dentalRecords', 
            'treatmentPlans', 
            'images', 
            'periodontalRecords',
            'softTissueRecords',
            'orthodonticRecords'
        ])->find($id);

        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        return response()->json($patient);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'avatar' => 'nullable|string',
            'email' => 'required|email',
            'date_of_birth' => 'required|date',
            'branch_id' => 'required|exists:branches,id',
            'history' => 'nullable|string',
        ]);

        $patient = Patient::create([
            ...$validated,
            'id' => 'p' . (Patient::count() + 1),
            'last_visit' => now()->format('Y-m-d'),
            'status' => 'Active',
            'occlusal_records' => [
                'overbite' => 0,
                'overjet' => 0,
                'crossbite' => 'None',
                'openbite' => 'None',
                'malocclusion' => 'Class I',
            ],
        ]);

        return response()->json($patient->load(['dentalRecords', 'treatmentPlans', 'images']), 201);
    }

    public function update(Request $request, $id)
    {
        $patient = Patient::find($id);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'avatar' => 'nullable|string',
            'email' => 'sometimes|email',
            'date_of_birth' => 'sometimes|date',
            'last_visit' => 'nullable|date',
            'status' => 'sometimes|in:Active,Inactive',
            'branch_id' => 'sometimes|exists:branches,id',
            'history' => 'nullable|string',
            'occlusal_records' => 'nullable|array',
        ]);

        $patient->update($validated);

        return response()->json($patient->load(['dentalRecords', 'treatmentPlans', 'images']));
    }

    public function destroy($id)
    {
        $patient = Patient::find($id);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $patient->delete();

        return response()->json(['success' => true]);
    }

    public function addImage(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'url' => 'required|string',
            'description' => 'required|string',
        ]);

        $image = $patient->images()->create([
            ...$validated,
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('images'));
    }

    public function saveDentalRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'tooth_id' => 'required|integer',
            'condition' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $existingRecord = $patient->dentalRecords()
            ->where('tooth_id', $validated['tooth_id'])
            ->first();

        if ($existingRecord) {
            $existingRecord->update($validated);
        } else {
            $patient->dentalRecords()->create($validated);
        }

        return response()->json($patient->load('dentalRecords'));
    }

    public function savePeriodontalRecords(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $records = $request->validate([
            'records' => 'required|array',
            'records.*.tooth_id' => 'required|integer',
            'records.*.pockets' => 'required|array',
            'records.*.recession' => 'required|integer',
            'records.*.mobility' => 'required|string',
            'records.*.furcation' => 'nullable|string',
            'records.*.conditions' => 'required|array',
        ]);

        $patient->periodontalRecords()->delete();
        
        foreach ($records['records'] as $record) {
            $patient->periodontalRecords()->create($record);
        }

        return response()->json($patient->load('periodontalRecords'));
    }

    public function saveOcclusalRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'overbite' => 'required|integer',
            'overjet' => 'required|integer',
            'crossbite' => 'required|string',
            'openbite' => 'required|string',
            'malocclusion' => 'required|string',
        ]);

        $patient->update(['occlusal_records' => $validated]);

        return response()->json($patient);
    }

    public function addTreatment(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'procedure' => 'required|string',
            'tooth' => 'nullable|integer',
            'status' => 'required|in:Proposed,Completed',
            'cost' => 'required|numeric',
            'date' => 'required|date',
        ]);

        $treatment = $patient->treatmentPlans()->create([
            ...$validated,
            'id' => 'tp' . (TreatmentPlan::count() + 1),
        ]);

        return response()->json($patient->load('treatmentPlans'));
    }

    public function addSoftTissueRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'location' => 'required|string',
            'type' => 'required|string',
            'description' => 'required|string',
        ]);

        $record = $patient->softTissueRecords()->create([
            ...$validated,
            'id' => 'st' . (SoftTissueRecord::count() + 1),
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('softTissueRecords'));
    }

    public function addOrthodonticRecord(Request $request, $patientId)
    {
        $patient = Patient::find($patientId);
        
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $validated = $request->validate([
            'appliance' => 'required|string',
            'wire_size' => 'nullable|string',
            'bracket_changes' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $record = $patient->orthodonticRecords()->create([
            ...$validated,
            'id' => 'or' . (OrthodonticRecord::count() + 1),
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($patient->load('orthodonticRecords'));
    }
}