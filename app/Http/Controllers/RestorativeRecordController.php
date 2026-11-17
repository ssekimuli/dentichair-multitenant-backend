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
            'cost' => 'nullable|numeric|min:0',
            'procedure_cost' => 'nullable|numeric|min:0',
            'material_cost' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
        ]);

        $procedureCost = $validated['procedure_cost'] ?? 0;
        $materialCost = $validated['material_cost'] ?? 0;
        $otherCharges = $validated['other_charges'] ?? 0;
        $totalCost = $procedureCost + $materialCost + $otherCharges;

        if ($totalCost == 0 && !empty($validated['cost']) && $validated['cost'] > 0) {
            $totalCost = $validated['cost'];
            $procedureCost = $totalCost;
        }

        $record = $patient->restorativeRecords()->create(
            \Illuminate\Support\Arr::except($validated, ['cost', 'procedure_cost', 'material_cost', 'other_charges']) + [
                'procedure_cost' => $procedureCost,
                'material_cost' => $materialCost,
                'other_charges' => $otherCharges,
            ]
        );

        if ($totalCost > 0) {
            $invoice = \App\Models\Invoice::where('patient_id', $patient->id)
                ->whereDate('issue_date', today())
                ->where('branch_id', $patient->branch_id)
                ->whereIn('status', ['Pending', 'Overdue'])
                ->first();
                
            if (!$invoice) {
                $invoice = \App\Models\Invoice::create([
                    'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                    'patient_id' => $patient->id,
                    'amount' => 0,
                    'paid_amount' => 0,
                    'status' => 'Pending',
                    'issue_date' => today(),
                    'due_date' => today()->addDays(30),
                    'branch_id' => $patient->branch_id,
                    'category' => 'procedure-income',
                ]);
            }

            $invoice->increment('amount', $totalCost);

            if ($procedureCost > 0) {
                $invoice->treatments()->create([
                    'procedure' => 'Restoration Tooth #' . $record->tooth_id . ' (' . $record->surface . ' - ' . $record->material . ') - Procedure',
                    'cost' => $procedureCost
                ]);
            }
            if ($materialCost > 0) {
                $invoice->treatments()->create([
                    'procedure' => 'Restoration Tooth #' . $record->tooth_id . ' - Material',
                    'cost' => $materialCost
                ]);
            }
            if ($otherCharges > 0) {
                $invoice->treatments()->create([
                    'procedure' => 'Restoration Tooth #' . $record->tooth_id . ' - Other Charges',
                    'cost' => $otherCharges
                ]);
            }
        }

        return response()->json($record, 201);
    }

    /**
     * Store multiple restorative records in one request.
     */
    public function storeBatch(Request $request, Patient $patient)
    {
        $request->validate([
            'records'                       => 'required|array|min:1',
            'records.*.tooth_id'            => 'required|integer',
            'records.*.surface'             => 'required|string',
            'records.*.material'            => 'required|string',
            'records.*.notes'               => 'nullable|string',
            'records.*.cost'                => 'nullable|numeric|min:0',
            'records.*.procedure_cost'      => 'nullable|numeric|min:0',
            'records.*.material_cost'       => 'nullable|numeric|min:0',
            'records.*.other_charges'       => 'nullable|numeric|min:0',
        ]);

        $created = [];
        $markingLines = [];

        foreach ($request->records as $item) {
            $procedureCost = $item['procedure_cost'] ?? 0;

            // Mirror store() fallback: if no procedure cost was sent but a generic cost was, keep it
            if ($procedureCost == 0 && !empty($item['cost']) && $item['cost'] > 0) {
                $procedureCost = $item['cost'];
            }

            // Chart markings are recorded in medical history only. They never create
            // a treatment plan or an invoice, whatever costs are sent.
            $record = $patient->restorativeRecords()->make([
                'tooth_id'       => $item['tooth_id'],
                'surface'        => $item['surface'],
                'material'       => $item['material'],
                'notes'          => $item['notes'] ?? null,
                'procedure_cost' => $procedureCost,
                'material_cost'  => $item['material_cost'] ?? 0,
                'other_charges'  => $item['other_charges'] ?? 0,
            ]);
            $record->skipTreatmentPlan = true;
            $record->save();

            $line = "- Tooth #{$record->tooth_id} ({$record->surface}): {$record->material}";
            if (!empty(trim($record->notes ?? ''))) {
                $line .= ': ' . trim($record->notes);
            }
            $markingLines[] = $line;

            $created[] = $record;
        }

        $this->addMarkingsToMedicalHistory($patient, $markingLines);

        return response()->json($created, 201);
    }

    /**
     * Add chart marking lines to the patient's medical history for today,
     * under intra_oral_exam. Existing lines are kept and duplicates are skipped.
     */
    private function addMarkingsToMedicalHistory(Patient $patient, array $lines): void
    {
        if (empty($lines)) {
            return;
        }

        $date = now()->toDateString();

        $history = \App\Models\MedicalHistory::where('patient_id', $patient->id)
            ->whereDate('date', $date)
            ->first();

        if (!$history) {
            $history = \App\Models\MedicalHistory::create([
                'patient_id'      => $patient->id,
                'date'            => $date,
                'chief_complaint' => 'Dental chart marking',
                'consent'         => true,
            ]);
        }

        $existing = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', (string) $history->intra_oral_exam),
            fn ($l) => trim($l) !== ''
        ));

        $newLines = array_values(array_filter(
            array_unique($lines),
            fn ($l) => !in_array($l, $existing, true)
        ));

        if (empty($newLines)) {
            return;
        }

        $history->intra_oral_exam = implode("\n", array_merge($existing, $newLines));
        $history->saveQuietly();
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
            'procedure_cost' => 'nullable|numeric|min:0',
            'material_cost' => 'nullable|numeric|min:0',
            'other_charges' => 'nullable|numeric|min:0',
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
