<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class ApproveQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => 'nullable|string|max:255',
            'customer_email' => 'nullable|email',
            'customer_phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'approval_method' => 'nullable|in:clinic_staff,patient_link,lab_client_link',
        ];
    }
}

