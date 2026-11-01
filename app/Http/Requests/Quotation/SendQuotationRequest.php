<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class SendQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channels' => 'nullable|array',
            'channels.*' => 'in:email,whatsapp,sms',
            'message' => 'nullable|string',
        ];
    }
}

