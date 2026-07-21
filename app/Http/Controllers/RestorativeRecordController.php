<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\RestorativeRecord;
use Illuminate\Http\Request;

class RestorativeRecordController extends Controller
{
    public function store(Request $request, Patient $patient)
    {
        $validated = $request->validate([
            'tooth_id' => 'required|integer',
            'surface' => 'required|string',
            'material' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $record = $patient->restorativeRecords()->create($validated);

        return response()->json($record, 201);
    }

    public function update(Request $request, Patient $patient, RestorativeRecord $restorativeRecord)
    {
        if ($restorativeRecord->patient_id !== $patient->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'tooth_id' => 'required|integer',
            'surface' => 'required|string',
            'material' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $restorativeRecord->update($validated);

        return response()->json($restorativeRecord);
    }

    public function destroy(Patient $patient, RestorativeRecord $restorativeRecord)
    {
        if ($restorativeRecord->patient_id !== $patient->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $restorativeRecord->delete();

        return response()->json(null, 204);
    }
}
