<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Model;

class TreatmentPlan extends Model
{
    use LogsActivity;
    //
    // procedure
    protected $fillable = ['procedure', 'cost', 'treatment_id', 'appointment_id', 'patient_id', 'doctor_id', 'branch_id', 'status', 'notes', 'date', 'time', 'token_number', 'tooth'];

    protected static function booted()
    {
        static::saved(function ($treatmentPlan) {
            self::syncInvoiceForTreatmentPlan($treatmentPlan);
            
            // If patient_id or date changed, sync the original patient/date as well
            if ($treatmentPlan->wasChanged(['patient_id', 'date'])) {
                $oldPatientId = $treatmentPlan->getOriginal('patient_id') ?? $treatmentPlan->patient_id;
                $oldDate = $treatmentPlan->getOriginal('date') ?? $treatmentPlan->date;
                self::syncInvoiceForPatientAndDate($oldPatientId, $oldDate);
            }
        });

        static::deleted(function ($treatmentPlan) {
            self::syncInvoiceForPatientAndDate($treatmentPlan->patient_id, $treatmentPlan->date);
        });
    }

    public static function syncInvoiceForTreatmentPlan($treatmentPlan)
    {
        // If status is not Completed, ensure it has no invoice_id
        if ($treatmentPlan->status !== 'Completed') {
            if ($treatmentPlan->invoice_id !== null) {
                $treatmentPlan->invoice_id = null;
                $treatmentPlan->saveQuietly();
            }
        }

        self::syncInvoiceForPatientAndDate($treatmentPlan->patient_id, $treatmentPlan->date);
    }

    public static function syncInvoiceForPatientAndDate($patientId, $date)
    {
        if (!$patientId || !$date) {
            return;
        }

        // Find all completed treatment plans for this patient on this date
        $completedTreatments = self::where('patient_id', $patientId)
            ->whereDate('date', $date)
            ->where('status', 'Completed')
            ->get();

        $patient = Patient::find($patientId);
        if (!$patient) {
            return;
        }

        if ($completedTreatments->isEmpty()) {
            // No completed treatments. If an invoice exists on this date, clean it up
            $invoice = Invoice::where('patient_id', $patientId)
                ->whereDate('issue_date', $date)
                ->first();

            if ($invoice) {
                $invoice->treatments()->delete();
                $otherChargesTotal = $invoice->otherCharges()->sum('amount');
                if ($otherChargesTotal == 0 && $invoice->paid_amount == 0 && !$invoice->transactions()->exists()) {
                    $invoice->delete();
                } else {
                    $invoice->update([
                        'amount' => $otherChargesTotal,
                        'status' => $invoice->paid_amount >= $otherChargesTotal ? 'Paid' : 'Pending',
                    ]);
                }
            }
            return;
        }

        // Find or create the invoice for this patient and date
        $invoice = Invoice::where('patient_id', $patientId)
            ->whereDate('issue_date', $date)
            ->first();

        if (!$invoice) {
            $invoice = Invoice::create([
                'patient_id' => $patient->id,
                'patient_name' => $patient->first_name . ' ' . $patient->last_name,
                'branch_id' => $patient->branch_id,
                'amount' => 0,
                'paid_amount' => 0,
                'status' => 'Pending',
                'issue_date' => $date,
                'due_date' => \Carbon\Carbon::parse($date)->addDays(30)->format('Y-m-d'),
            ]);
        }

        // Fetch patient's primary active policy
        $primaryPolicy = $patient->insurancePolicies()
            ->with('package.packageProcedures')
            ->wherePivot('is_primary', true)
            ->wherePivot('status', 'active')
            ->first();

        // Delete existing treatments for this invoice and recreate them from current completed treatments
        $invoice->treatments()->delete();
        $treatmentsTotal = 0;
        $insuranceResponsibilityTotal = 0;
        $patientResponsibilityTotal = 0;

        foreach ($completedTreatments as $ct) {
            $cost = $ct->cost;
            $insuranceAmount = 0;
            $patientAmount = $cost;
            
            $treatmentModel = \App\Models\Treatment::where('procedure', $ct->procedure)->first();
            $procedureId = $treatmentModel ? $treatmentModel->id : null;

            if ($primaryPolicy && $primaryPolicy->package && $procedureId) {
                // Check if specific rule exists for this procedure
                $rule = $primaryPolicy->package->packageProcedures->where('procedure_id', $procedureId)->first();
                if ($rule) {
                    if ($rule->coverage_type === 'percentage') {
                        $insuranceAmount = ($rule->coverage_value / 100) * $cost;
                    } elseif ($rule->coverage_type === 'fixed_amount') {
                        $insuranceAmount = min($rule->coverage_value, $cost);
                    }
                    if ($rule->maximum_covered_amount && $insuranceAmount > $rule->maximum_covered_amount) {
                        $insuranceAmount = $rule->maximum_covered_amount;
                    }
                    $patientAmount = $cost - $insuranceAmount;
                } else {
                    // Check default package coverage
                    $defaultCoverage = $primaryPolicy->package->default_coverage_percentage ?? 0;
                    if ($defaultCoverage > 0) {
                        $insuranceAmount = ($defaultCoverage / 100) * $cost;
                        $patientAmount = $cost - $insuranceAmount;
                    }
                }
            }

            $invoice->treatments()->create([
                'procedure' => $ct->procedure,
                'procedure_id' => $procedureId,
                'cost' => $cost,
                'unit_price' => $cost,
                'quantity' => 1,
                'total_amount' => $cost,
                'insurance_amount' => $insuranceAmount,
                'patient_amount' => $patientAmount
            ]);

            $treatmentsTotal += $cost;
            $insuranceResponsibilityTotal += $insuranceAmount;
            $patientResponsibilityTotal += $patientAmount;
        }

        // Calculate total amount
        $otherChargesTotal = $invoice->otherCharges()->sum('amount');
        
        $medicinesTotal = 0;
        $medicines = is_string($invoice->getRawOriginal('medicines')) 
            ? json_decode($invoice->getRawOriginal('medicines'), true) 
            : $invoice->getRawOriginal('medicines');
            
        if (is_array($medicines)) {
            foreach ($medicines as $med) {
                $medicinesTotal += (float)($med['price'] ?? 0) * (int)($med['quantity'] ?? 1);
            }
        }

        $subtotal = $treatmentsTotal + $otherChargesTotal + $medicinesTotal;
        $discountAmount = $subtotal * ((float)($invoice->discount ?? 0) / 100);
        $taxAmount = ($subtotal - $discountAmount) * ((float)($invoice->tax ?? 0) / 100);
        
        $newTotal = $subtotal - $discountAmount + $taxAmount;
        
        // Add medicines and other charges to patient responsibility, adjusted for discount/tax
        $patientResponsibilityTotal += $otherChargesTotal + $medicinesTotal - $discountAmount + $taxAmount;

        $patientPaid = $invoice->patient_paid_amount ?? 0;
        $insurancePaid = $invoice->insurance_paid_amount ?? 0;

        $invoice->update([
            'amount' => $newTotal,
            'insurance_responsibility' => $insuranceResponsibilityTotal,
            'patient_responsibility' => $patientResponsibilityTotal,
            'patient_balance' => max(0, $patientResponsibilityTotal - $patientPaid),
            'insurance_balance' => max(0, $insuranceResponsibilityTotal - $insurancePaid),
            'status' => ($patientPaid >= $patientResponsibilityTotal) ? 'Paid' : 'Pending',
        ]);

        // Link completed treatments to this invoice
        foreach ($completedTreatments as $ct) {
            $expectedInvoiceId = 'inv' . $invoice->id;
            if ($ct->invoice_id !== $expectedInvoiceId) {
                $ct->invoice_id = $expectedInvoiceId;
                $ct->saveQuietly();
            }
        }
    }


}
