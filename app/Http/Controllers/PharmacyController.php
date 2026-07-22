<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Models\MedicineUnit;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Patient;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PharmacyController extends Controller
{
    // --- Medicine Inventory Management ---

    /**
     * Get Medicines List
     */
    public function getMedicines(Request $request)
    {
        $query = Medicine::with(['units']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('category', 'like', '%' . $request->search . '%');
        }

        $medicines = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'data' => $medicines->items(),
            'meta' => [
                'current_page' => $medicines->currentPage(),
                'last_page' => $medicines->lastPage(),
                'per_page' => $medicines->perPage(),
                'total' => $medicines->total(),
            ]
        ]);
    }

    /**
     * Create Medicine with Units
     */
    public function storeMedicine(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'category' => 'nullable|string',
            'branch_id' => 'required|exists:branches,id',
            'units' => 'required|array|min:1',
            'units.*.unit_name' => 'required|string',
            'units.*.price' => 'required|numeric|min:0',
            'units.*.stock_quantity' => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($validated) {
            $medicine = Medicine::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'category' => $validated['category'] ?? null,
                'branch_id' => $validated['branch_id'],
            ]);

            foreach ($validated['units'] as $unitData) {
                $medicine->units()->create($unitData);
            }

            return response()->json($medicine->load('units'), 201);
        });
    }

    /**
     * Update Medicine and Units
     */
    public function updateMedicine(Request $request, $id)
    {
        $medicine = Medicine::find($id);
        if (!$medicine) {
            return response()->json(['error' => 'Medicine not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'category' => 'nullable|string',
            'branch_id' => 'sometimes|exists:branches,id',
            'units' => 'sometimes|array',
            'units.*.id' => 'nullable|exists:medicine_units,id',
            'units.*.unit_name' => 'required|string',
            'units.*.price' => 'required|numeric|min:0',
            'units.*.stock_quantity' => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($medicine, $validated) {
            $medicine->update(collect($validated)->except('units')->toArray());

            if (isset($validated['units'])) {
                // Keep track of unit IDs to delete ones that are removed
                $keepIds = [];
                foreach ($validated['units'] as $unitData) {
                    if (isset($unitData['id'])) {
                        $unit = MedicineUnit::find($unitData['id']);
                        if ($unit && $unit->medicine_id == $medicine->id) {
                            $unit->update($unitData);
                            $keepIds[] = $unit->id;
                        }
                    } else {
                        $newUnit = $medicine->units()->create($unitData);
                        $keepIds[] = $newUnit->id;
                    }
                }
                $medicine->units()->whereNotIn('id', $keepIds)->delete();
            }

            return response()->json($medicine->load('units'));
        });
    }

    /**
     * Delete Medicine
     */
    public function deleteMedicine($id)
    {
        $medicine = Medicine::find($id);
        if (!$medicine) {
            return response()->json(['error' => 'Medicine not found'], 404);
        }

        $medicine->delete();
        return response()->json(['success' => true]);
    }

    // --- Prescription Processing & Billing ---

    /**
     * Get All Prescriptions
     */
    public function getPrescriptions(Request $request)
    {
        $query = Prescription::with(['patient', 'doctor', 'invoice', 'items.medicine', 'items.unit']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }

        $prescriptions = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'data' => $prescriptions->items(),
            'meta' => [
                'current_page' => $prescriptions->currentPage(),
                'last_page' => $prescriptions->lastPage(),
                'per_page' => $prescriptions->perPage(),
                'total' => $prescriptions->total(),
            ]
        ]);
    }

    /**
     * Get Patient Prescriptions
     */
    public function getPatientPrescriptions($patientId)
    {
        $patient = Patient::find($patientId);
        if (!$patient) {
            return response()->json(['error' => 'Patient not found'], 404);
        }

        $prescriptions = Prescription::with(['doctor', 'invoice', 'items.medicine', 'items.unit'])
            ->where('patient_id', $patientId)
            ->latest()
            ->get();

        return response()->json($prescriptions);
    }

    /**
     * Create Prescription & Auto Bill to Invoice
     */
    public function storePrescription(Request $request)
    {
        $validated = $request->validate([
            'patient_id' => 'nullable|exists:patients,id',
            'doctor_id' => 'nullable|exists:employees,id',
            'prescription_date' => 'required|date',
            'notes' => 'nullable|string',
            'branch_id' => 'required|exists:branches,id',
            'bill_to_invoice' => 'boolean', // option to auto-bill
            'items' => 'required|array|min:1',
            'items.*.medicine_id' => 'required|exists:medicines,id',
            'items.*.medicine_unit_id' => 'required|exists:medicine_units,id',
            'items.*.dosage' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0', // price at time of prescribing
        ]);

        return DB::transaction(function () use ($validated) {
            $patient = isset($validated['patient_id']) ? Patient::find($validated['patient_id']) : null;
            $doctorName = null;
            if (isset($validated['doctor_id'])) {
                $doctor = \App\Models\Employee::find($validated['doctor_id']);
                if ($doctor) {
                    $doctorName = $doctor->name;
                }
            }

            // Create prescription
            $prescription = Prescription::create([
                'patient_id' => $validated['patient_id'] ?? null,
                'patient_name' => $patient ? $patient->first_name . ' ' . $patient->last_name : null,
                'doctor_id' => $validated['doctor_id'] ?? null,
                'doctor_name' => $doctorName,
                'prescription_date' => $validated['prescription_date'],
                'notes' => $validated['notes'] ?? null,
                'branch_id' => $validated['branch_id'],
                'status' => 'Pending',
            ]);

            $totalAmount = 0;
            $prescriptionItems = [];

            foreach ($validated['items'] as $itemData) {
                $item = $prescription->items()->create($itemData);
                $totalAmount += $itemData['price'] * $itemData['quantity'];
                $prescriptionItems[] = $item;

                // Subtract from inventory stock
                $unit = MedicineUnit::find($itemData['medicine_unit_id']);
                if ($unit) {
                    $unit->decrement('stock_quantity', $itemData['quantity']);
                }
            }

            // Auto-bill to invoice if requested (defaults to true)
            $billToInvoice = $validated['bill_to_invoice'] ?? true;
            if ($billToInvoice && $totalAmount > 0) {
                // Prepare medicines array
                $medicinesList = [];
                foreach ($validated['items'] as $itemData) {
                    $medicinesList[] = [
                        'medicine_id' => $itemData['medicine_id'],
                        'medicine_unit_id' => $itemData['medicine_unit_id'],
                        'quantity' => $itemData['quantity'],
                        'price' => $itemData['price'],
                    ];
                }

                // Check if a pending invoice already exists for this patient, date, and branch
                $invoice = null;
                if ($prescription->patient_id) {
                    $invoice = Invoice::where('patient_id', $prescription->patient_id)
                        ->whereDate('issue_date', \Carbon\Carbon::parse($prescription->prescription_date)->format('Y-m-d'))
                        ->where('branch_id', $prescription->branch_id)
                        ->whereIn('status', ['Pending', 'Overdue'])
                        ->first();
                }

                if ($invoice) {
                    // Update existing invoice amount and append medicines
                    $existingMedsStr = $invoice->getRawOriginal('medicines');
                    $existingMeds = $existingMedsStr ? json_decode($existingMedsStr, true) : [];
                    $mergedMeds = array_merge($existingMeds ?: [], $medicinesList);
                    $invoice->increment('amount', $totalAmount);
                    $invoice->update(['medicines' => $mergedMeds]);
                } else {
                    // Create a new Invoice
                    $invoice = Invoice::create([
                        'patient_name' => $prescription->patient_name,
                        'patient_id' => $prescription->patient_id,
                        'amount' => $totalAmount,
                        'paid_amount' => 0,
                        'status' => 'Pending',
                        'issue_date' => $prescription->prescription_date,
                        'due_date' => Carbon::parse($prescription->prescription_date)->addDays(30),
                        'branch_id' => $prescription->branch_id,
                        'medicines' => $medicinesList,
                    ]);
                }

                // Link Invoice to Prescription
                $prescription->update([
                    'invoice_id' => $invoice->id,
                    'status' => 'Billed',
                ]);
            }

            return response()->json($prescription->load(['items.medicine', 'items.unit', 'invoice']), 201);
        });
    }

    /**
     * Update Prescription Status
     */
    public function updatePrescriptionStatus(Request $request, $id)
    {
        $prescription = Prescription::find($id);
        if (!$prescription) {
            return response()->json(['error' => 'Prescription not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:Pending,Dispensed,Billed',
        ]);

        $prescription->update(['status' => $validated['status']]);

        return response()->json($prescription->load(['items.medicine', 'items.unit', 'invoice']));
    }

    /**
     * Update Prescription
     */
    public function updatePrescription(Request $request, $id)
    {
        $prescription = Prescription::with('items')->find($id);
        if (!$prescription) {
            return response()->json(['error' => 'Prescription not found'], 404);
        }

        $validated = $request->validate([
            'prescription_date' => 'sometimes|date',
            'notes' => 'nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.medicine_id' => 'required|exists:medicines,id',
            'items.*.medicine_unit_id' => 'required|exists:medicine_units,id',
            'items.*.dosage' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($prescription, $validated) {
            $prescription->update(collect($validated)->except('items')->toArray());

            if (isset($validated['items'])) {
                // To keep it simple, delete old items and create new ones
                $prescription->items()->delete();
                foreach ($validated['items'] as $itemData) {
                    $prescription->items()->create($itemData);
                }
            }

            return response()->json($prescription->load(['items.medicine', 'items.unit', 'invoice']));
        });
    }

    /**
     * Delete Prescription
     */
    public function deletePrescription($id)
    {
        $prescription = Prescription::find($id);
        if (!$prescription) {
            return response()->json(['error' => 'Prescription not found'], 404);
        }

        $prescription->items()->delete();
        $prescription->delete();

        return response()->json(['success' => true]);
    }
}

