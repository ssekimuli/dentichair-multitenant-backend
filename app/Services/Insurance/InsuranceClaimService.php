<?php

namespace App\Services\Insurance;

use App\Models\Invoice;
use App\Models\InsuranceClaim;
use App\Models\ClaimItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class InsuranceClaimService
{
    public function generateClaimFromInvoice(Invoice $invoice, $patientPolicy): InsuranceClaim
    {
        if ($invoice->insurance_balance <= 0) {
            throw new Exception("This invoice has no outstanding insurance balance.");
        }

        return DB::transaction(function () use ($invoice, $patientPolicy) {
            $claim = InsuranceClaim::create([
                'claim_number' => 'CLM-' . strtoupper(Str::random(8)),
                'invoice_id' => $invoice->id,
                'patient_id' => $invoice->patient_id,
                'insurance_policy_id' => $patientPolicy->insurance_policy_id,
                'insurance_company_id' => $patientPolicy->policy->insurance_company_id,
                'submitted_amount' => $invoice->insurance_responsibility,
                'outstanding_amount' => $invoice->insurance_responsibility,
                'status' => 'Draft',
                'submitted_by' => auth()->id(),
            ]);

            foreach ($invoice->items as $item) {
                if ($item->insurance_amount > 0) {
                    ClaimItem::create([
                        'insurance_claim_id' => $claim->id,
                        'invoice_item_id' => $item->id,
                        'procedure_id' => $item->procedure_id,
                        'claimed_amount' => $item->insurance_amount,
                        'status' => 'Pending',
                    ]);
                }
            }

            return $claim;
        });
    }

    public function settleClaim(InsuranceClaim $claim, array $settlementData)
    {
        return DB::transaction(function () use ($claim, $settlementData) {
            $invoice = Invoice::where('id', $claim->invoice_id)->lockForUpdate()->first();
            $claim = InsuranceClaim::where('id', $claim->id)->lockForUpdate()->first();

            $totalApproved = $settlementData['approved_amount'];
            $totalRejected = $settlementData['rejected_amount'];

            if (($totalApproved + $totalRejected) > $claim->submitted_amount) {
                throw new Exception("Settled amount cannot exceed submitted amount.");
            }

            foreach ($settlementData['items'] as $itemData) {
                $claimItem = ClaimItem::findOrFail($itemData['claim_item_id']);
                
                $claimItem->update([
                    'approved_amount' => $itemData['approved_amount'],
                    'rejected_amount' => $itemData['rejected_amount'],
                    'rejection_reason' => $itemData['rejection_reason'] ?? null,
                    'status' => $itemData['rejected_amount'] > 0 ? 'Partially Approved' : 'Approved',
                ]);
                
                if ($itemData['rejected_amount'] == $claimItem->claimed_amount) {
                    $claimItem->update(['status' => 'Rejected']);
                }
            }

            $claim->update([
                'approved_amount' => $totalApproved,
                'rejected_amount' => $totalRejected,
                'outstanding_amount' => $claim->submitted_amount - ($totalApproved + $totalRejected),
                'status' => $settlementData['status'],
                'approval_date' => now(),
            ]);

            if ($totalRejected > 0) {
                $invoice->insurance_balance -= $totalRejected;
                $invoice->insurance_responsibility -= $totalRejected;
                
                $invoice->patient_responsibility += $totalRejected;
                $invoice->patient_balance += $totalRejected;
                
                $invoice->save();
            }

            return $claim;
        });
    }
}
