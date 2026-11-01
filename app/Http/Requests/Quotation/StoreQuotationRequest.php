<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_type' => 'required|in:patient,lab_client',
            'patient_id' => 'required_if:customer_type,patient|nullable|integer|exists:patients,id',
            'lab_client_id' => 'required_if:customer_type,lab_client|nullable|integer|exists:clients,id',
            'dentist_id' => 'nullable|integer|exists:employees,id',
            'lab_order_id' => 'nullable|integer|exists:laboratory_orders,id',
            'source_type' => 'nullable|in:manual,treatment_plan,lab_order,mixed',
            'quotation_date' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:quotation_date',
            'currency' => 'nullable|string|max:10',
            'discount_type' => 'nullable|in:percentage,fixed,none',
            'discount_amount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'terms_conditions' => 'nullable|string',
            'expected_delivery_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
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

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $hasPatient = !empty($this->input('patient_id'));
            $hasLabClient = !empty($this->input('lab_client_id'));

            if ($hasPatient && $hasLabClient) {
                $v->errors()->add('customer_type', 'A quotation must belong to either a patient OR a laboratory client, never both.');
            }
            if (!$hasPatient && !$hasLabClient) {
                $v->errors()->add('customer_type', 'A quotation must belong to either a patient or a laboratory client.');
            }
        });
    }
}

