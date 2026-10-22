<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class TreatmentPlan extends Model
{
    use LogsActivity;

    protected $fillable = [
        'procedure',
        'cost',
        'treatment_id',
        'appointment_id',
        'patient_id',
        'doctor_id',
        'branch_id',
        'status',
        'notes',
        'date',
        'time',
        'token_number',
        'tooth',
        'invoice_id',
        'restorative_record_id',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'date' => 'date',
    ];

    protected $appends = ['doctor_name'];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'doctor_id');
    }

    public function getDoctorNameAttribute(): ?string
    {
        return $this->doctor?->name;
    }

    public function restorativeRecord(): BelongsTo
    {
        return $this->belongsTo(RestorativeRecord::class, 'restorative_record_id');
    }

    protected static function booted(): void
    {
        static::saved(function (TreatmentPlan $treatmentPlan) {
            $oldPatientId = $treatmentPlan->getOriginal('patient_id');
            $oldDate = $treatmentPlan->getOriginal('date');

            self::syncInvoiceForTreatmentPlan($treatmentPlan);
            self::syncMedicalHistoryForTreatmentPlan($treatmentPlan);

            /*
             * If the patient or treatment date changed, recalculate
             * the invoice and medical history belonging to the previous patient/date.
             */
            if ($treatmentPlan->wasChanged(['patient_id', 'date'])) {
                if ($oldPatientId && $oldDate) {
                    self::syncInvoiceForPatientAndDate(
                        $oldPatientId,
                        $oldDate
                    );
                    self::syncMedicalHistoryForPatientAndDate(
                        $oldPatientId,
                        $oldDate
                    );
                }
            }
        });

        static::deleted(function (TreatmentPlan $treatmentPlan) {
            self::syncInvoiceForPatientAndDate(
                $treatmentPlan->patient_id,
                $treatmentPlan->date
            );
            self::syncMedicalHistoryForPatientAndDate(
                $treatmentPlan->patient_id,
                $treatmentPlan->date
            );
        });
    }

    public static function syncInvoiceForTreatmentPlan(
        TreatmentPlan $treatmentPlan
    ): void {
        /*
         * A treatment that is not completed should not remain
         * connected to an invoice.
         */
        if ($treatmentPlan->status !== 'Completed') {
            if ($treatmentPlan->invoice_id !== null) {
                $treatmentPlan->invoice_id = null;
                $treatmentPlan->saveQuietly();
            }
        }

        self::syncInvoiceForPatientAndDate(
            $treatmentPlan->patient_id,
            $treatmentPlan->date
        );
    }

    public static function syncInvoiceForPatientAndDate(
        $patientId,
        $date
    ): void {
        if (!$patientId || !$date) {
            return;
        }

        DB::transaction(function () use ($patientId, $date) {
            $formattedDate = Carbon::parse($date)->format('Y-m-d');

            $patient = Patient::find($patientId);

            if (!$patient) {
                return;
            }

            // Clean up any treatment plans linked to soft-deleted invoices so they are never re-billed
            $trashedInvoiceIds = Invoice::onlyTrashed()->pluck('id')->toArray();
            if (!empty($trashedInvoiceIds)) {
                $trashedKeys = array_merge(
                    array_map(fn($id) => (string) $id, $trashedInvoiceIds),
                    array_map(fn($id) => 'inv' . $id, $trashedInvoiceIds)
                );
                self::where('patient_id', $patientId)
                    ->whereDate('date', $formattedDate)
                    ->whereIn('invoice_id', $trashedKeys)
                    ->delete();
            }

            $completedTreatments = self::query()
                ->where('patient_id', $patientId)
                ->whereDate('date', $formattedDate)
                ->where('status', 'Completed')
                ->get();

            /*
             * This application assumes that a patient has one invoice
             * per branch per day. When a new treatment is added to a day
             * that already has a Paid invoice, that invoice is reopened:
             * the status recalculation below will set it back to Pending
             * if the new total exceeds what has already been paid.
             */
            $invoiceQuery = Invoice::query()
                ->where('patient_id', $patientId)
                ->whereDate('issue_date', $formattedDate);

            if ($patient->branch_id) {
                $invoiceQuery->where('branch_id', $patient->branch_id);
            }

            $invoice = $invoiceQuery
                ->lockForUpdate()
                ->first();

            /*
             * If no completed treatments remain, remove the generated
             * treatment lines and recalculate the remaining invoice.
             */
            if ($completedTreatments->isEmpty()) {
                if (!$invoice) {
                    return;
                }

                $invoice->treatments()->delete();

                self::recalculateInvoiceWithoutTreatments($invoice);

                return;
            }

            /*
             * Create an invoice when one does not already exist.
             */
            if (!$invoice) {
                $invoice = Invoice::create([
                    'patient_id' => $patient->id,
                    'patient_name' => trim(
                        $patient->first_name . ' ' . $patient->last_name
                    ),
                    'branch_id' => $patient->branch_id,
                    'amount' => 0,
                    'paid_amount' => 0,
                    'patient_paid_amount' => 0,
                    'insurance_paid_amount' => 0,
                    'insurance_responsibility' => 0,
                    'patient_responsibility' => 0,
                    'patient_balance' => 0,
                    'insurance_balance' => 0,
                    'status' => 'Pending',
                    'category' => 'procedure-income',
                    'issue_date' => $formattedDate,
                    'due_date' => Carbon::parse($formattedDate)
                        ->addDays(30)
                        ->format('Y-m-d'),
                ]);
            }

            /*
             * Get the patient's primary active insurance policy.
             */
            $primaryPolicy = $patient->insurancePolicies()
                ->with('package.packageProcedures')
                ->wherePivot('is_primary', true)
                ->wherePivot('status', 'active')
                ->first();

            /*
             * Recreate treatment invoice lines using the current
             * completed treatments.
             */
            $invoice->treatments()->delete();

            $treatmentsTotal = 0.0;
            $insuranceResponsibilityTotal = 0.0;
            $patientResponsibilityTotal = 0.0;

            foreach ($completedTreatments as $completedTreatment) {
                $cost = max(0, (float) $completedTreatment->cost);

                $insuranceAmount = 0.0;
                $patientAmount = $cost;

                /*
                 * Prefer the stored treatment_id. Fall back to matching
                 * the procedure name for older records.
                 */
                $procedureId = $completedTreatment->treatment_id;

                if (!$procedureId && $completedTreatment->procedure) {
                    $procedureId = Treatment::query()
                        ->where('procedure', $completedTreatment->procedure)
                        ->value('id');
                }

                if (
                    $primaryPolicy &&
                    $primaryPolicy->package &&
                    $procedureId
                ) {
                    $procedureRule = $primaryPolicy
                        ->package
                        ->packageProcedures
                        ->firstWhere('procedure_id', $procedureId);

                    if ($procedureRule) {
                        $insuranceAmount = self::calculateInsuranceAmount(
                            $cost,
                            $procedureRule
                        );
                    } else {
                        $defaultCoverage = (float) (
                            $primaryPolicy
                                ->package
                                ->default_coverage_percentage ?? 0
                        );

                        $defaultCoverage = max(
                            0,
                            min(100, $defaultCoverage)
                        );

                        $insuranceAmount = (
                            $defaultCoverage / 100
                        ) * $cost;
                    }

                    $insuranceAmount = max(
                        0,
                        min($cost, $insuranceAmount)
                    );

                    $patientAmount = max(
                        0,
                        $cost - $insuranceAmount
                    );
                }

                $insuranceAmount = round($insuranceAmount, 2);
                $patientAmount = round($patientAmount, 2);

                $invoice->treatments()->create([
                    'procedure' => $completedTreatment->procedure,
                    'procedure_id' => $procedureId,
                    'cost' => $cost,
                    'unit_price' => $cost,
                    'quantity' => 1,
                    'total_amount' => $cost,
                    'insurance_amount' => $insuranceAmount,
                    'patient_amount' => $patientAmount,
                ]);

                $treatmentsTotal += $cost;
                $insuranceResponsibilityTotal += $insuranceAmount;
                $patientResponsibilityTotal += $patientAmount;
            }

            $otherChargesTotal = (float) $invoice
                ->otherCharges()
                ->sum('amount');

            $medicinesTotal = self::calculateMedicinesTotal($invoice);

            $subtotal = (
                $treatmentsTotal +
                $otherChargesTotal +
                $medicinesTotal
            );

            $discountPercentage = max(
                0,
                min(100, (float) ($invoice->discount ?? 0))
            );

            $taxPercentage = max(
                0,
                (float) ($invoice->tax ?? 0)
            );

            $discountAmount = (
                $subtotal * ($discountPercentage / 100)
            );

            $taxableAmount = max(
                0,
                $subtotal - $discountAmount
            );

            $taxAmount = (
                $taxableAmount * ($taxPercentage / 100)
            );

            $newTotal = (
                $subtotal -
                $discountAmount +
                $taxAmount
            );

            /*
             * Medicines, other charges, discounts, and taxes are assigned
             * to the patient responsibility.
             */
            $patientResponsibilityTotal += (
                $otherChargesTotal +
                $medicinesTotal -
                $discountAmount +
                $taxAmount
            );

            $patientResponsibilityTotal = max(
                0,
                $patientResponsibilityTotal
            );

            $patientPaid = self::getPatientPaidAmount($invoice);
            $insurancePaid = (float) (
                $invoice->insurance_paid_amount ?? 0
            );

            $patientBalance = max(
                0,
                $patientResponsibilityTotal - $patientPaid
            );

            $insuranceBalance = max(
                0,
                $insuranceResponsibilityTotal - $insurancePaid
            );

            /*
             * Only mark the invoice as Paid when both balances are
             * settled AND there has been at least some payment activity
             * (or there is genuinely nothing owed). This prevents a
             * freshly-created invoice from being auto-marked as Paid
             * just because an insurance-covered treatment leaves the
             * patient owing zero — the insurance portion is still
             * outstanding until an actual payment is recorded.
             */
            $hasPaymentActivity = (
                $patientPaid > 0 ||
                $insurancePaid > 0 ||
                $invoice->transactions()->exists()
            );

            $invoiceStatus = (
                $patientBalance <= 0 &&
                $insuranceBalance <= 0 &&
                ($hasPaymentActivity || ($newTotal <= 0))
            )
                ? 'Paid'
                : 'Pending';

            $invoice->update([
                'amount' => round($newTotal, 2),
                'insurance_responsibility' => round(
                    $insuranceResponsibilityTotal,
                    2
                ),
                'patient_responsibility' => round(
                    $patientResponsibilityTotal,
                    2
                ),
                'patient_balance' => round($patientBalance, 2),
                'insurance_balance' => round($insuranceBalance, 2),
                'status' => $invoiceStatus,
            ]);

            /*
             * Link each completed treatment plan to the invoice.
             *
             * If invoice_id is an integer foreign key, store $invoice->id.
             * The previous "inv" prefix would break an integer foreign key.
             */
            foreach ($completedTreatments as $completedTreatment) {
                if ((string) $completedTreatment->invoice_id !== (string) $invoice->id) {
                    $completedTreatment->invoice_id = $invoice->id;
                    $completedTreatment->saveQuietly();
                }
            }
        });
    }

    private static function calculateInsuranceAmount(
        float $cost,
        $procedureRule
    ): float {
        $coverageValue = max(
            0,
            (float) ($procedureRule->coverage_value ?? 0)
        );

        $insuranceAmount = 0.0;

        if ($procedureRule->coverage_type === 'percentage') {
            $coveragePercentage = min(100, $coverageValue);

            $insuranceAmount = (
                $coveragePercentage / 100
            ) * $cost;
        }

        if ($procedureRule->coverage_type === 'fixed_amount') {
            $insuranceAmount = min(
                $coverageValue,
                $cost
            );
        }

        $maximumCoveredAmount = (float) (
            $procedureRule->maximum_covered_amount ?? 0
        );

        if (
            $maximumCoveredAmount > 0 &&
            $insuranceAmount > $maximumCoveredAmount
        ) {
            $insuranceAmount = $maximumCoveredAmount;
        }

        return max(
            0,
            min($cost, $insuranceAmount)
        );
    }

    private static function calculateMedicinesTotal(
        Invoice $invoice
    ): float {
        $rawMedicines = $invoice->getRawOriginal('medicines');

        if (is_string($rawMedicines)) {
            $medicines = json_decode(
                $rawMedicines,
                true
            );
        } else {
            $medicines = $rawMedicines;
        }

        if (!is_array($medicines)) {
            return 0.0;
        }

        $medicinesTotal = 0.0;

        foreach ($medicines as $medicine) {
            if (!is_array($medicine)) {
                continue;
            }

            $price = max(
                0,
                (float) ($medicine['price'] ?? 0)
            );

            $quantity = max(
                0,
                (int) ($medicine['quantity'] ?? 1)
            );

            $medicinesTotal += $price * $quantity;
        }

        return $medicinesTotal;
    }

    private static function getPatientPaidAmount(
        Invoice $invoice
    ): float {
        /*
         * Prefer patient_paid_amount. Use paid_amount as a fallback
         * for invoices created before insurance payments were separated.
         */
        if ($invoice->patient_paid_amount !== null) {
            return max(
                0,
                (float) $invoice->patient_paid_amount
            );
        }

        return max(
            0,
            (float) ($invoice->paid_amount ?? 0)
        );
    }

    private static function recalculateInvoiceWithoutTreatments(
        Invoice $invoice
    ): void {
        $otherChargesTotal = (float) $invoice
            ->otherCharges()
            ->sum('amount');

        $medicinesTotal = self::calculateMedicinesTotal($invoice);

        $subtotal = (
            $otherChargesTotal +
            $medicinesTotal
        );

        $discountPercentage = max(
            0,
            min(100, (float) ($invoice->discount ?? 0))
        );

        $taxPercentage = max(
            0,
            (float) ($invoice->tax ?? 0)
        );

        $discountAmount = (
            $subtotal * ($discountPercentage / 100)
        );

        $taxableAmount = max(
            0,
            $subtotal - $discountAmount
        );

        $taxAmount = (
            $taxableAmount * ($taxPercentage / 100)
        );

        $newTotal = max(
            0,
            $subtotal - $discountAmount + $taxAmount
        );

        $patientPaid = self::getPatientPaidAmount($invoice);

        $insurancePaid = max(
            0,
            (float) ($invoice->insurance_paid_amount ?? 0)
        );

        $patientBalance = max(
            0,
            $newTotal - $patientPaid
        );

        /*
         * When all treatment lines have been removed, the insurance is
         * no longer responsible for any amount. Only other charges and
         * medicines remain, which are the patient's responsibility.
         */
        $insuranceBalance = 0.0;

        $hasTransactions = $invoice
            ->transactions()
            ->exists();

        /*
         * Delete only when the invoice has no remaining charges
         * and no payment history.
         */
        if (
            $newTotal <= 0 &&
            $patientPaid <= 0 &&
            $insurancePaid <= 0 &&
            !$hasTransactions
        ) {
            $invoice->delete();

            return;
        }

        $status = (
            $patientBalance <= 0 &&
            $insuranceBalance <= 0
        )
            ? 'Paid'
            : 'Pending';

        $invoice->update([
            'amount' => round($newTotal, 2),
            'insurance_responsibility' => 0,
            'patient_responsibility' => round($newTotal, 2),
            'patient_balance' => round($patientBalance, 2),
            'insurance_balance' => 0,
            'status' => $status,
        ]);
    }

    /**
     * Synchronize medical history for a treatment plan.
     */
    public static function syncMedicalHistoryForTreatmentPlan(
        TreatmentPlan $treatmentPlan
    ): void {
        self::syncMedicalHistoryForPatientAndDate(
            $treatmentPlan->patient_id,
            $treatmentPlan->date
        );
    }

    /**
     * Synchronize medical history for a patient and specific date.
     * All treatment plans marked Completed on the same date for the patient
     * are consolidated into ONE single MedicalHistory record.
     */
    public static function syncMedicalHistoryForPatientAndDate(
        $patientId,
        $date
    ): void {
        if (!$patientId || !$date) {
            return;
        }

        DB::transaction(function () use ($patientId, $date) {
            $formattedDate = Carbon::parse($date)->format('Y-m-d');

            $patient = Patient::find($patientId);
            if (!$patient) {
                return;
            }

            // Find all treatment plans for this patient on this date that are marked Completed
            $completedTreatments = self::query()
                ->where('patient_id', $patientId)
                ->whereDate('date', $formattedDate)
                ->where('status', 'Completed')
                ->orderBy('id', 'asc')
                ->get();

            // Find existing MedicalHistory for this patient on this date
            $medHistory = MedicalHistory::where('patient_id', $patientId)
                ->whereDate('date', $formattedDate)
                ->first();

            // Case A: No completed treatments on this date
            if ($completedTreatments->isEmpty()) {
                if ($medHistory) {
                    $isAutoCreated = ($medHistory->chief_complaint === 'Dental Treatment / Procedure Completion');
                    $hasOtherClinicalData = !empty($medHistory->extra_oral_exam) ||
                                           !empty($medHistory->intra_oral_exam) ||
                                           !empty($medHistory->consent_notes);

                    if ($isAutoCreated && !$hasOtherClinicalData) {
                        $medHistory->delete();
                    } else {
                        $lines = array_filter(
                            preg_split('/\r\n|\r|\n/', (string) $medHistory->treatment_given),
                            fn($l) => !str_starts_with(trim($l), '-')
                        );
                        $medHistory->treatment_given = !empty($lines) ? implode("\n", $lines) : null;
                        $medHistory->saveQuietly();
                    }
                }
                return;
            }

            // Build consolidated procedure lines from all completed treatments
            $allGivenLines = [];
            $allPlanLines = [];
            $allNotes = [];
            $doctorName = null;
            $doctorId = null;

            foreach ($completedTreatments as $tp) {
                $procName = trim($tp->procedure ?? '');
                $toothStr = !empty($tp->tooth) ? " (Tooth #{$tp->tooth})" : "";
                $notesStr = !empty(trim($tp->notes ?? '')) ? ": " . trim($tp->notes) : "";

                $lineGiven = "- {$procName}{$toothStr}{$notesStr}";
                $linePlan = "- {$procName}{$toothStr}";

                $allGivenLines[] = $lineGiven;
                $allPlanLines[] = $linePlan;

                if (!empty(trim($tp->notes ?? ''))) {
                    $allNotes[] = trim($tp->notes);
                }

                if (!empty($tp->doctor?->name)) {
                    $doctorName = $tp->doctor->name;
                } elseif (!empty($tp->doctor_name)) {
                    $doctorName = $tp->doctor_name;
                }

                if (!empty($tp->doctor_id)) {
                    $doctorId = $tp->doctor_id;
                }
            }

            // Clinician attribution fallback
            if (!$doctorName && auth()->check()) {
                $doctorName = auth()->user()->name;
            }
            if (!$doctorId && auth()->check()) {
                $doctorId = auth()->id();
            }
            $doctorName = $doctorName ?: 'Attending Clinician';

            $uniquePlanLines = array_values(array_unique($allPlanLines));
            $uniqueGivenLines = array_values(array_unique($allGivenLines));
            $uniqueNotes = array_values(array_unique($allNotes));

            // Case B: No MedicalHistory exists yet for this patient on this date -> Create ONE record
            if (!$medHistory) {
                MedicalHistory::create([
                    'patient_id'        => $patientId,
                    'date'              => $formattedDate,
                    'chief_complaint'   => 'Dental Treatment / Procedure Completion',
                    'history_complaint' => 'Patient attended for scheduled clinical dental treatment.',
                    'diagnosis'         => 'Clinical dental treatment performed per treatment plan.',
                    'treatment_plan'    => implode("\n", $uniquePlanLines),
                    'treatment_given'   => implode("\n", $uniqueGivenLines),
                    'treatment_note'    => !empty($uniqueNotes) ? implode("\n", $uniqueNotes) : 'Completed clinical dental treatment.',
                    'doctor_name'       => $doctorName,
                    'doctor_id'         => $doctorId,
                    'consent'           => true,
                ]);
            } else {
                // Case C: MedicalHistory ALREADY exists for this patient on this date -> Consolidate into ONE record!
                $isAutoCreated = ($medHistory->chief_complaint === 'Dental Treatment / Procedure Completion');

                if ($isAutoCreated) {
                    $medHistory->treatment_given = implode("\n", $uniqueGivenLines);
                    $medHistory->treatment_plan = implode("\n", $uniquePlanLines);
                    if (!empty($uniqueNotes)) {
                        $medHistory->treatment_note = implode("\n", $uniqueNotes);
                    }
                    if (!empty($doctorName) && empty($medHistory->doctor_name)) {
                        $medHistory->doctor_name = $doctorName;
                    }
                    if (!empty($doctorId) && empty($medHistory->doctor_id)) {
                        $medHistory->doctor_id = $doctorId;
                    }
                    $medHistory->saveQuietly();
                } else {
                    $existingGiven = trim($medHistory->treatment_given ?? '');
                    $existingLines = !empty($existingGiven) ? preg_split('/\r\n|\r|\n/', $existingGiven) : [];

                    $retainedLines = [];
                    foreach ($existingLines as $el) {
                        $trimmedEl = trim($el);
                        $matchesCompleted = false;
                        foreach ($completedTreatments as $tp) {
                            $prefix = "- " . trim($tp->procedure) . (!empty($tp->tooth) ? " (Tooth #{$tp->tooth})" : "");
                            if (str_starts_with($trimmedEl, $prefix)) {
                                $matchesCompleted = true;
                                break;
                            }
                        }
                        if (!$matchesCompleted) {
                            $retainedLines[] = $el;
                        }
                    }

                    $mergedGiven = array_merge($retainedLines, $uniqueGivenLines);
                    $medHistory->treatment_given = implode("\n", array_filter($mergedGiven, fn($l) => trim($l) !== ''));

                    $existingPlan = trim($medHistory->treatment_plan ?? '');
                    if (empty($existingPlan)) {
                        $medHistory->treatment_plan = implode("\n", $uniquePlanLines);
                    } else {
                        $planLines = preg_split('/\r\n|\r|\n/', $existingPlan);
                        foreach ($uniquePlanLines as $upl) {
                            if (!in_array($upl, $planLines)) {
                                $planLines[] = $upl;
                            }
                        }
                        $medHistory->treatment_plan = implode("\n", $planLines);
                    }

                    if (empty($medHistory->doctor_name) && !empty($doctorName)) {
                        $medHistory->doctor_name = $doctorName;
                    }
                    if (empty($medHistory->doctor_id) && !empty($doctorId)) {
                        $medHistory->doctor_id = $doctorId;
                    }

                    $medHistory->saveQuietly();
                }
            }
        });
    }
}