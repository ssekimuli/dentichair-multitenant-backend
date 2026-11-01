<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dentist_id' => 'nullable|integer|exists:employees,id',
            'quotation_date' => 'sometimes|date',
            'valid_until' => 'sometimes|date',
            'currency' => 'nullable|string|max:10',
            'discount_type' => 'nullable|in:percentage,fixed,none',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'terms_conditions' => 'nullable|string',
            'expected_delivery_date' => 'nullable|date',
            'items' => 'sometimes|array|min:1',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.discount_type' => 'nullable|in:percentage,fixed,none',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.tooth_number' => 'nullable|string|max:50',
            'items.*.material' => 'nullable|string|max:100',
            'items.*.shade' => 'nullable|string|max:50',
            'items.*.item_type' => 'nullable|string|max:50',
            'items.*.service_id' => 'nullable|integer',
            'items.*.treatment_plan_item_id' => 'nullable|integer',
            'items.*.lab_order_item_id' => 'nullable|integer',
            'items.*.special_instructions' => 'nullable|string',
            'items.*.expected_delivery_date' => 'nullable|date',
            'items.*.sort_order' => 'nullable|integer',
        ];
    }
}

