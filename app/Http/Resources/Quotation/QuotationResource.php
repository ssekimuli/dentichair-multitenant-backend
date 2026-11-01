<?php

namespace App\Http\Resources\Quotation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quotation_number' => $this->quotation_number,
            'customer_type' => $this->customer_type,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'patient_id' => $this->patient_id,
            'lab_client_id' => $this->lab_client_id,
            'dentist_id' => $this->dentist_id,
            'lab_order_id' => $this->lab_order_id,
            'branch_id' => $this->branch_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'quotation_date' => $this->quotation_date?->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'is_expired' => $this->is_expired,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount_type' => $this->discount_type,
            'discount_amount' => (float) $this->discount_amount,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'total' => (float) $this->total,
            'status' => $this->status,
            'notes' => $this->notes,
            'terms_conditions' => $this->terms_conditions,
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'converted_invoice_id' => $this->converted_invoice_id,
            'original_quotation_id' => $this->original_quotation_id,
            'revision_number' => $this->revision_number,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Relationships
            'patient' => $this->whenLoaded('patient', function () {
                return $this->patient ? [
                    'id' => $this->patient->id,
                    'name' => trim(($this->patient->first_name ?? '') . ' ' . ($this->patient->last_name ?? '')),
                    'first_name' => $this->patient->first_name,
                    'last_name' => $this->patient->last_name,
                    'email' => $this->patient->email,
                    'phone' => $this->patient->phone,
                    'gender' => $this->patient->gender,
                    'age' => $this->patient->age,
                ] : null;
            }),
            'lab_client' => $this->whenLoaded('labClient', function () {
                return $this->labClient ? [
                    'id' => $this->labClient->id,
                    'name' => $this->labClient->name,
                    'email' => $this->labClient->email,
                    'phone' => $this->labClient->phone,
                    'address' => $this->labClient->address,
                ] : null;
            }),
            'dentist' => $this->whenLoaded('dentist', function () {
                return $this->dentist ? [
                    'id' => $this->dentist->id,
                    'name' => $this->dentist->name,
                    'email' => $this->dentist->email,
                    'role' => $this->dentist->role,
                ] : null;
            }),
            'lab_order' => $this->whenLoaded('labOrder', function () {
                return $this->labOrder ? [
                    'id' => $this->labOrder->id,
                    'item' => $this->labOrder->item,
                    'tooth' => $this->labOrder->tooth,
                    'status' => $this->labOrder->status,
                    'material' => $this->labOrder->material,
                    'shade' => $this->labOrder->shade,
                ] : null;
            }),
            'branch' => $this->whenLoaded('branch', function () {
                return $this->branch ? [
                    'id' => $this->branch->id,
                    'name' => $this->branch->name,
                    'currency' => $this->branch->currency,
                ] : null;
            }),
            'invoice' => $this->whenLoaded('invoice', function () {
                return $this->invoice ? [
                    'id' => $this->invoice->id,
                    'receipt_number' => $this->invoice->receipt_number,
                    'amount' => (float) $this->invoice->amount,
                    'paid_amount' => (float) $this->invoice->paid_amount,
                    'status' => $this->invoice->status,
                    'issue_date' => $this->invoice->issue_date?->format('Y-m-d'),
                ] : null;
            }),
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'service_id' => $item->service_id,
                        'treatment_plan_item_id' => $item->treatment_plan_item_id,
                        'lab_order_item_id' => $item->lab_order_item_id,
                        'item_type' => $item->item_type,
                        'description' => $item->description,
                        'tooth_number' => $item->tooth_number,
                        'material' => $item->material,
                        'shade' => $item->shade,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'discount_type' => $item->discount_type,
                        'discount_amount' => (float) $item->discount_amount,
                        'tax_rate' => (float) $item->tax_rate,
                        'tax_amount' => (float) $item->tax_amount,
                        'total' => (float) $item->total,
                        'expected_delivery_date' => $item->expected_delivery_date?->format('Y-m-d'),
                        'special_instructions' => $item->special_instructions,
                        'sort_order' => (int) $item->sort_order,
                    ];
                });
            }),
            'approvals' => $this->whenLoaded('approvals'),
            'status_histories' => $this->whenLoaded('statusHistories'),
        ];
    }
}

