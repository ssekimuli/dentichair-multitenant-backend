<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class RejectQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'nullable|string',
            'customer_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ];
    }
}

