<?php

namespace App\Services\Insurance;

use App\Models\PatientInsurancePolicy;
use App\Models\Procedure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Exception;

class InsuranceBillingService
{
    protected $eligibilityService;

    public function __construct(InsuranceEligibilityService $eligibilityService)
    {
        $this->eligibilityService = $eligibilityService;
    }

    public function calculateEstimate(PatientInsurancePolicy $patientPolicy, array $procedureIdsWithQuantities): array
    {
        $estimate = [
            'total_amount' => 0,
            'insurance_responsibility' => 0,
            'patient_responsibility' => 0,
            'items' => []
        ];

        foreach ($procedureIdsWithQuantities as $item) {
            $procedure = Procedure::findOrFail($item['procedure_id']);
            $quantity = $item['quantity'] ?? 1;
            $unitPrice = $procedure->price ?? 0;
            $totalPrice = $unitPrice * $quantity;
            
            $estimate['total_amount'] += $totalPrice;

            try {
                $rule = $this->eligibilityService->checkProcedureCoverage($patientPolicy, $procedure);
                
                $insuranceAmount = 0;
                if ($rule->coverage_type === 'percentage') {
                    $insuranceAmount = $totalPrice * ($rule->coverage_value / 100);
                } else {
                    $insuranceAmount = min($rule->coverage_value * $quantity, $totalPrice);
                }

                if ($rule->maximum_covered_amount && $insuranceAmount > $rule->maximum_covered_amount) {
                    $insuranceAmount = $rule->maximum_covered_amount;
                }

                $patientAmount = $totalPrice - $insuranceAmount;

                $estimate['insurance_responsibility'] += $insuranceAmount;
                $estimate['patient_responsibility'] += $patientAmount;

                $estimate['items'][] = [
                    'procedure_id' => $procedure->id,
                    'name' => $procedure->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalPrice,
                    'insurance_amount' => $insuranceAmount,
                    'patient_amount' => $patientAmount,
                    'rule_snapshot' => $rule->toArray()
                ];

            } catch (Exception $e) {
                $estimate['patient_responsibility'] += $totalPrice;
                
                $estimate['items'][] = [
                    'procedure_id' => $procedure->id,
                    'name' => $procedure->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalPrice,
                    'insurance_amount' => 0,
                    'patient_amount' => $totalPrice,
                    'rule_snapshot' => null,
                    'note' => $e->getMessage()
                ];
            }
        }

        return $estimate;
    }

    public function createInsuredInvoice(PatientInsurancePolicy $patientPolicy, array $procedureData, int $patientId): Invoice
    {
        return DB::transaction(function () use ($patientPolicy, $procedureData, $patientId) {
            $estimate = $this->calculateEstimate($patientPolicy, $procedureData);

            $invoice = Invoice::create([
                'patient_id' => $patientId,
                'total_amount' => $estimate['total_amount'],
                'patient_responsibility' => $estimate['patient_responsibility'],
                'insurance_responsibility' => $estimate['insurance_responsibility'],
                'patient_balance' => $estimate['patient_responsibility'],
                'insurance_balance' => $estimate['insurance_responsibility'],
                'payment_status' => 'Unpaid',
            ]);

            foreach ($estimate['items'] as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'procedure_id' => $item['procedure_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_amount' => $item['total_amount'],
                    'covered_amount' => $item['insurance_amount'] > 0 ? $item['total_amount'] : 0,
                    'insurance_amount' => $item['insurance_amount'],
                    'patient_amount' => $item['patient_amount'],
                    'excluded_amount' => $item['total_amount'] - $item['insurance_amount'] - $item['patient_amount'],
                    'coverage_percentage' => $item['rule_snapshot'] ? $item['rule_snapshot']['coverage_value'] : 0,
                    'coverage_rule_snapshot' => $item['rule_snapshot'],
                ]);
            }

            return $invoice;
        });
    }
}
