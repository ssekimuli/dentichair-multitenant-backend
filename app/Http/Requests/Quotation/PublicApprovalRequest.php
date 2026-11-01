<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class PublicApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'nullable|email',
            'customer_phone' => 'nullable|string|max:50',
            'consent_confirmed' => 'required|accepted',
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'consent_confirmed.accepted' => 'You must confirm your consent and agreement to approve this quotation.',
        ];
    }
}

