<?php

namespace App\Services\Quotation;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceTreatment;
use App\Models\LaboratoryOrder;
use App\Models\Quotation;
use App\Models\QuotationStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationConversionService
{
    /**
     * Convert an approved quotation into an Invoice.
     * Uses row-level locking and database transactions for concurrency safety.
     *
     * @param Quotation $quotation
     * @param int $branchId
     * @param int|null $userId
     * @return Invoice
     * @throws ValidationException
     */
    public function convertToInvoice(Quotation $quotation, int $branchId, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($quotation, $branchId, $userId) {
            // Lock quotation row to prevent duplicate conversion race conditions
            /** @var Quotation $lockedQuotation */
            $lockedQuotation = Quotation::where('id', $quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Validate tenant branch ownership
            if ($lockedQuotation->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'branch_id' => ['Unauthorized branch: Quotation does not belong to the current branch.']
                ]);
            }

            // 2. Validate quotation status
            if ($lockedQuotation->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => ["Only 'approved' quotations can be converted into invoices. Current status is '{$lockedQuotation->status}'."]
                ]);
            }

            // 3. Prevent duplicate conversion
            if ($lockedQuotation->converted_invoice_id !== null) {
                $existingInvoice = Invoice::find($lockedQuotation->converted_invoice_id);
                if ($existingInvoice) {
                    throw ValidationException::withMessages([
                        'invoice' => ["Quotation #{$lockedQuotation->quotation_number} has already been converted to invoice #{$existingInvoice->receipt_number}."]
                    ]);
                }
            }

            // 4. Validate customer relationship
            if ($lockedQuotation->customer_type === 'patient') {
                if (empty($lockedQuotation->patient_id) || !$lockedQuotation->patient) {
                    throw ValidationException::withMessages([
                        'patient_id' => ['The associated patient for this quotation could not be found.']
                    ]);
                }
            } else {
                if (empty($lockedQuotation->lab_client_id) || !$lockedQuotation->labClient) {
                    throw ValidationException::withMessages([
                        'lab_client_id' => ['The associated laboratory client for this quotation could not be found.']
                    ]);
                }
            }

            // 5. Load quotation items
            $items = $lockedQuotation->items()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['Cannot convert quotation with no items.']
                ]);
            }

            $category = $lockedQuotation->customer_type === 'patient' ? 'procedure-income' : 'lab-income';
            $issueDate = now()->format('Y-m-d');
            $dueDate = $lockedQuotation->valid_until
                ? $lockedQuotation->valid_until->format('Y-m-d')
                : now()->addDays(30)->format('Y-m-d');

            // 6. Create Invoice using standard Invoice model
            $invoiceData = [
                'patient_name' => $lockedQuotation->customer_name,
                'patient_id' => $lockedQuotation->customer_type === 'patient' ? $lockedQuotation->patient_id : null,
                'client_id' => $lockedQuotation->customer_type === 'lab_client' ? $lockedQuotation->lab_client_id : null,
                'amount' => $lockedQuotation->total,
                'patient_responsibility' => $lockedQuotation->total,
                'patient_balance' => $lockedQuotation->total,
                'insurance_responsibility' => 0.00,
                'insurance_balance' => 0.00,
                'patient_paid_amount' => 0.00,
                'insurance_paid_amount' => 0.00,
                'paid_amount' => 0.00,
                'discount' => $lockedQuotation->discount_amount,
                'tax' => $lockedQuotation->tax_amount,
                'insurance_coverage' => 0.00,
                'status' => 'Pending',
                'category' => $category,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'branch_id' => $branchId,
                'employee_id' => $lockedQuotation->dentist_id ?? $userId,
            ];

            $invoice = Invoice::create($invoiceData);

            // 7. Map quotation items to Invoice Treatments
            foreach ($items as $item) {
                $description = $item->description;
                if (!empty($item->tooth_number)) {
                    $description .= " (Tooth #{$item->tooth_number})";
                }
                if (!empty($item->material)) {
                    $description .= " [Material: {$item->material}]";
                }
                if (!empty($item->shade)) {
                    $description .= " [Shade: {$item->shade}]";
                }

                InvoiceTreatment::create([
                    'invoice_id' => $invoice->id,
                    'procedure' => $description,
                    'procedure_id' => $item->service_id,
                    'cost' => $item->total,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_amount' => $item->total,
                    'patient_amount' => $item->total,
                    'covered_amount' => 0.00,
                    'insurance_amount' => 0.00,
                    'excluded_amount' => 0.00,
                    'coverage_percentage' => 0.00,
                ]);
            }

            // 8. If linked to a laboratory order, connect the invoice
            if ($lockedQuotation->lab_order_id) {
                $labOrder = LaboratoryOrder::where('id', $lockedQuotation->lab_order_id)
                    ->where('branch_id', $branchId)
                    ->first();
                if ($labOrder && empty($labOrder->invoice_id)) {
                    $labOrder->update(['invoice_id' => $invoice->id]);
                }
            }

            // 9. Mark quotation as converted and link invoice
            $lockedQuotation->update([
                'converted_invoice_id' => $invoice->id,
                'status' => 'converted',
                'updated_by' => $userId,
            ]);

            // 10. Record status history
            QuotationStatusHistory::create([
                'quotation_id' => $lockedQuotation->id,
                'old_status' => 'approved',
                'new_status' => 'converted',
                'changed_by' => $userId,
                'change_source' => 'staff',
                'notes' => "Quotation converted to Invoice #{$invoice->receipt_number} (Invoice ID: {$invoice->id}).",
                'created_at' => now(),
            ]);

            ActivityLog::log(
                'Quotation Converted to Invoice',
                "Quotation #{$lockedQuotation->quotation_number} was successfully converted to Invoice #{$invoice->receipt_number}.",
                [
                    'quotation_id' => $lockedQuotation->id,
                    'invoice_id' => $invoice->id,
                    'receipt_number' => $invoice->receipt_number,
                    'amount' => $invoice->amount,
                ]
            );

            return $invoice->load(['treatments', 'patient', 'client', 'branch']);
        });
    }
}

