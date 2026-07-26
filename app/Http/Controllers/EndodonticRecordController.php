<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\EndodonticRecord;
use Illuminate\Http\Request;

class EndodonticRecordController extends Controller
{
    public function store(Request $request, Patient $patient)
    {
        $validated = $request->validate([
            'tooth_id' => 'required|integer',
            'pulpal_diagnosis' => 'required|string',
            'periapical_diagnosis' => 'required|string',
            'canals' => 'required|array',
            'obturation_material' => 'nullable|string',
            'post_type' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $record = $patient->endodonticRecords()->create([
            ...$validated,
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->json($record, 201);
    }

    public function update(Request $request, Patient $patient, EndodonticRecord $endodonticRecord)
    {
        if ($endodonticRecord->patient_id !== $patient->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'tooth_id' => 'sometimes|required|integer',
            'pulpal_diagnosis' => 'sometimes|required|string',
            'periapical_diagnosis' => 'sometimes|required|string',
            'canals' => 'sometimes|required|array',
            'obturation_material' => 'nullable|string',
            'post_type' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $endodonticRecord->update($validated);

        return response()->json($endodonticRecord);
    }

    public function destroy(Patient $patient, EndodonticRecord $endodonticRecord)
    {
        if ($endodonticRecord->patient_id !== $patient->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $endodonticRecord->delete();

        return response()->json(null, 204);
    }
}
