<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class CreateFromLabOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lab_order_id' => 'sometimes|required|integer|exists:laboratory_orders,id',
            'quotation_date' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'currency' => 'nullable|string|max:10',
            'discount_type' => 'nullable|in:percentage,fixed,none',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'terms_conditions' => 'nullable|string',
            'expected_delivery_date' => 'nullable|date',
            'additional_items' => 'nullable|array',
            'additional_items.*.description' => 'required_with:additional_items|string|max:255',
            'additional_items.*.quantity' => 'required_with:additional_items|integer|min:1',
            'additional_items.*.unit_price' => 'required_with:additional_items|numeric|min:0',
            'additional_items.*.discount_type' => 'nullable|in:percentage,fixed,none',
            'additional_items.*.discount_amount' => 'nullable|numeric|min:0',
            'additional_items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'additional_items.*.tooth_number' => 'nullable|string|max:50',
            'additional_items.*.material' => 'nullable|string|max:100',
            'additional_items.*.shade' => 'nullable|string|max:50',
            'additional_items.*.special_instructions' => 'nullable|string',
        ];
    }
}

