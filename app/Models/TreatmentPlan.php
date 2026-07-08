<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreatmentPlan extends Model
{
    //
    // procedure
    protected $fillable = ['procedure', 'cost', 'treatment_id', 'appointment_id', 'patient_id', 'doctor_id', 'branch_id', 'status', 'notes', 'date', 'time', 'token_number'];

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

        // Delete existing treatments for this invoice and recreate them from current completed treatments
        $invoice->treatments()->delete();
        foreach ($completedTreatments as $ct) {
            $invoice->treatments()->create([
                'procedure' => $ct->procedure,
                'cost' => $ct->cost,
            ]);
        }

        // Calculate total amount
        $treatmentsTotal = $completedTreatments->sum('cost');
        $otherChargesTotal = $invoice->otherCharges()->sum('amount');
        $newTotal = $treatmentsTotal + $otherChargesTotal;

        $invoice->update([
            'amount' => $newTotal,
            'status' => $invoice->paid_amount >= $newTotal ? 'Paid' : 'Pending',
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
