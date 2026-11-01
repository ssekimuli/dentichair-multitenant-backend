<?php

namespace App\Services\Quotation;

use App\Models\ActivityLog;
use App\Models\Quotation;
use App\Models\QuotationStatusHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationRevisionService
{
    public function __construct(
        protected QuotationService $quotationService,
        protected QuotationStatusService $statusService,
        protected QuotationCalculationService $calculationService,
        protected QuotationNumberService $numberService
    ) {}

    /**
     * Renew an expired quotation with a new validity date.
     */
    public function renew(Quotation $quotation, array $data, ?int $userId = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $data, $userId) {
            $newValidUntil = !empty($data['valid_until'])
                ? Carbon::parse($data['valid_until'])
                : now()->addDays(30);

            if ($newValidUntil->endOfDay()->isPast()) {
                throw ValidationException::withMessages([
                    'valid_until' => ['The renewal date must be a future date.']
                ]);
            }

            $oldStatus = $quotation->status;
            $quotation->valid_until = $newValidUntil->format('Y-m-d');
            $quotation->updated_by = $userId;
            $quotation->save();

            // Set status to draft or sent
            $targetStatus = $data['status'] ?? 'draft';
            if (!in_array($targetStatus, ['draft', 'sent'])) {
                $targetStatus = 'draft';
            }

            if ($quotation->status !== $targetStatus) {
                $quotation->status = $targetStatus;
                $quotation->save();

                QuotationStatusHistory::create([
                    'quotation_id' => $quotation->id,
                    'old_status' => $oldStatus,
                    'new_status' => $targetStatus,
                    'changed_by' => $userId,
                    'change_source' => 'staff',
                    'notes' => "Quotation renewed until {$quotation->valid_until->format('Y-m-d')}.",
                    'created_at' => now(),
                ]);
            }

            ActivityLog::log(
                'Quotation Renewed',
                "Quotation #{$quotation->quotation_number} validity was renewed until {$quotation->valid_until->format('Y-m-d')}.",
                ['quotation_id' => $quotation->id, 'valid_until' => $quotation->valid_until]
            );

            return $quotation->fresh(['items', 'patient', 'labClient']);
        });
    }

    /**
     * Create a new revised quotation linked to the original version.
     */
    public function revise(Quotation $original, array $data, ?int $userId = null): Quotation
    {
        return DB::transaction(function () use ($original, $data, $userId) {
            if ($original->status === 'converted') {
                throw ValidationException::withMessages([
                    'status' => ['Cannot revise a quotation that has already been converted into an invoice.']
                ]);
            }

            $revisionNumber = $original->revision_number + 1;
            $newQuotationNumber = $this->numberService->generate($original->branch_id) . "-R{$revisionNumber}";

            // Determine items
            $itemsData = $data['items'] ?? [];
            if (empty($itemsData)) {
                // Duplicate original items
                $itemsData = $original->items->map(function ($item) {
                    return [
                        'service_id' => $item->service_id,
                        'treatment_plan_item_id' => $item->treatment_plan_item_id,
                        'lab_order_item_id' => $item->lab_order_item_id,
                        'item_type' => $item->item_type,
                        'description' => $item->description,
                        'tooth_number' => $item->tooth_number,
                        'material' => $item->material,
                        'shade' => $item->shade,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'discount_type' => $item->discount_type,
                        'discount_amount' => $item->discount_amount,
                        'tax_rate' => $item->tax_rate,
                        'tax_amount' => $item->tax_amount,
                        'total' => $item->total,
                        'expected_delivery_date' => $item->expected_delivery_date?->format('Y-m-d'),
                        'special_instructions' => $item->special_instructions,
                        'sort_order' => $item->sort_order,
                    ];
                })->toArray();
            }

            $calculatedItems = [];
            foreach ($itemsData as $index => $itemRaw) {
                $calc = $this->calculationService->calculateItem($itemRaw);
                $calculatedItems[] = array_merge($itemRaw, $calc, [
                    'sort_order' => $itemRaw['sort_order'] ?? $index,
                ]);
            }

            $discountType = $data['discount_type'] ?? $original->discount_type;
            $discountAmount = isset($data['discount_amount']) ? (float) $data['discount_amount'] : (float) $original->discount_amount;
            $taxRate = isset($data['tax_rate']) ? (float) $data['tax_rate'] : (float) $original->tax_rate;

            $totals = $this->calculationService->calculateQuotation(
                $calculatedItems,
                $discountType,
                $discountAmount,
                $taxRate
            );

            $revised = Quotation::create([
                'quotation_number' => $newQuotationNumber,
                'customer_type' => $original->customer_type,
                'patient_id' => $original->patient_id,
                'lab_client_id' => $original->lab_client_id,
                'dentist_id' => $data['dentist_id'] ?? $original->dentist_id,
                'lab_order_id' => $original->lab_order_id,
                'branch_id' => $original->branch_id,
                'source_type' => $original->source_type,
                'source_id' => $original->source_id,
                'quotation_date' => $data['quotation_date'] ?? now()->format('Y-m-d'),
                'valid_until' => $data['valid_until'] ?? now()->addDays(30)->format('Y-m-d'),
                'currency' => $original->currency,
                'subtotal' => $totals['subtotal'],
                'discount_type' => $totals['discount_type'],
                'discount_amount' => $totals['discount_amount'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? $original->notes,
                'terms_conditions' => $data['terms_conditions'] ?? $original->terms_conditions,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? $original->expected_delivery_date?->format('Y-m-d'),
                'original_quotation_id' => $original->id,
                'revision_number' => $revisionNumber,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            foreach ($calculatedItems as $cItem) {
                $revised->items()->create([
                    'service_id' => $cItem['service_id'] ?? null,
                    'treatment_plan_item_id' => $cItem['treatment_plan_item_id'] ?? null,
                    'lab_order_item_id' => $cItem['lab_order_item_id'] ?? null,
                    'item_type' => $cItem['item_type'] ?? 'procedure',
                    'description' => $cItem['description'],
                    'tooth_number' => $cItem['tooth_number'] ?? null,
                    'material' => $cItem['material'] ?? null,
                    'shade' => $cItem['shade'] ?? null,
                    'quantity' => $cItem['quantity'],
                    'unit_price' => $cItem['unit_price'],
                    'discount_type' => $cItem['discount_type'],
                    'discount_amount' => $cItem['discount_amount'],
                    'tax_rate' => $cItem['tax_rate'],
                    'tax_amount' => $cItem['tax_amount'],
                    'total' => $cItem['total'],
                    'expected_delivery_date' => $cItem['expected_delivery_date'] ?? null,
                    'special_instructions' => $cItem['special_instructions'] ?? null,
                    'sort_order' => $cItem['sort_order'],
                ]);
            }

            // Record history
            QuotationStatusHistory::create([
                'quotation_id' => $revised->id,
                'old_status' => null,
                'new_status' => 'draft',
                'changed_by' => $userId,
                'change_source' => 'staff',
                'notes' => "Revised version created from #{$original->quotation_number}.",
                'created_at' => now(),
            ]);

            ActivityLog::log(
                'Quotation Revised',
                "Quotation #{$original->quotation_number} was revised as #{$revised->quotation_number} (Rev #{$revisionNumber}).",
                ['original_id' => $original->id, 'revised_id' => $revised->id]
            );

            return $revised->fresh(['items', 'patient', 'labClient', 'originalQuotation']);
        });
    }
}

