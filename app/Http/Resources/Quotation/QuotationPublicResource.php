<?php

namespace App\Http\Resources\Quotation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'quotation_number' => $this->quotation_number,
            'customer_type' => $this->customer_type,
            'customer_name' => $this->customer_name,
            'quotation_date' => $this->quotation_date?->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'is_expired' => $this->is_expired,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'total' => (float) $this->total,
            'status' => $this->status,
            'terms_conditions' => $this->terms_conditions,
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'can_be_decided' => $this->status === 'sent' && !$this->is_expired,
            'clinic' => [
                'name' => $this->branch?->name ?? 'Dental Practice',
                'currency' => $this->branch?->currency ?? 'USD',
            ],
            'items' => $this->items->map(function ($item) {
                return [
                    'description' => $item->description,
                    'tooth_number' => $item->tooth_number,
                    'material' => $item->material,
                    'shade' => $item->shade,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'total' => (float) $item->total,
                ];
            }),
        ];
    }
}

