<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class CreateFromTreatmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'treatment_plan_item_ids' => 'required|array|min:1',
            'treatment_plan_item_ids.*' => 'required|integer|exists:treatment_plans,id',
            'dentist_id' => 'nullable|integer|exists:employees,id',
            'quotation_date' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'currency' => 'nullable|string|max:10',
            'discount_type' => 'nullable|in:percentage,fixed,none',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'terms_conditions' => 'nullable|string',
            'additional_items' => 'nullable|array',
            'additional_items.*.description' => 'required_with:additional_items|string|max:255',
            'additional_items.*.quantity' => 'required_with:additional_items|integer|min:1',
            'additional_items.*.unit_price' => 'required_with:additional_items|numeric|min:0',
            'additional_items.*.discount_type' => 'nullable|in:percentage,fixed,none',
            'additional_items.*.discount_amount' => 'nullable|numeric|min:0',
            'additional_items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'additional_items.*.tooth_number' => 'nullable|string|max:50',
            'additional_items.*.special_instructions' => 'nullable|string',
        ];
    }
}

