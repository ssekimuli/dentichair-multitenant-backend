<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceTreatment;
use App\Models\InvoiceOtherCharge;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with(['patient', 'branch', 'treatments', 'otherCharges', 'transactions']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->get();

        return response()->json($invoices);
    }

    public function show($id)
    {
        $invoice = Invoice::with(['patient', 'branch', 'treatments', 'otherCharges', 'transactions'])->find($id);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        return response()->json($invoice);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_name' => 'required|string',
            'patient_id' => 'required|exists:patients,id',
            'amount' => 'required|numeric',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'branch_id' => 'required|exists:branches,id',
            'treatments' => 'required|array',
            'treatments.*.procedure' => 'required|string',
            'treatments.*.cost' => 'required|numeric',
            'other_charges' => 'nullable|array',
            'other_charges.*.description' => 'required|string',
            'other_charges.*.amount' => 'required|numeric',
        ]);

        $invoice = Invoice::create([
            ...$validated,
            'id' => 'inv' . (Invoice::count() + 1),
            'paid_amount' => 0,
            'status' => 'Pending',
        ]);

        foreach ($validated['treatments'] as $treatment) {
            $invoice->treatments()->create($treatment);
        }

        if (isset($validated['other_charges'])) {
            foreach ($validated['other_charges'] as $charge) {
                $invoice->otherCharges()->create($charge);
            }
        }

        return response()->json($invoice->load(['treatments', 'otherCharges']), 201);
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::find($id);
        
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'patient_name' => 'sometimes|string',
            'patient_id' => 'sometimes|exists:patients,id',
            'amount' => 'sometimes|numeric',
            'paid_amount' => 'sometimes|numeric',
            'status' => 'sometimes|in:Pending,Paid,Overdue',
            'issue_date' => 'sometimes|date',
            'due_date' => 'sometimes|date',
            'branch_id' => 'sometimes|exists:branches,id',
            'payment_method' => 'nullable|string',
        ]);

        $invoice->update($validated);

        return response()->json($invoice->load(['treatments', 'otherCharges', 'transactions']));
    }

    public function addPayment(Request $request, $id)
    {
        $invoice = Invoice::find($id);
        
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric',
            'method' => 'required|string',
        ]);

        $transaction = $invoice->transactions()->create([
            ...$validated,
            'id' => 'txn' . (PaymentTransaction::count() + 1),
            'date' => now()->format('Y-m-d'),
        ]);

        $newPaidAmount = $invoice->paid_amount + $validated['amount'];
        $invoice->update([
            'paid_amount' => $newPaidAmount,
            'status' => $newPaidAmount >= $invoice->amount ? 'Paid' : 'Pending',
            'payment_method' => $validated['method'],
        ]);

        return response()->json($invoice->load(['treatments', 'otherCharges', 'transactions']));
    }

    public function destroy($id)
    {
        $invoice = Invoice::find($id);
        
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $invoice->delete();

        return response()->json(['success' => true]);
    }
}