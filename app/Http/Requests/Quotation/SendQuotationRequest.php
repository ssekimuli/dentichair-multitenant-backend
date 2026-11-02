<?php

namespace App\Http\Requests\Quotation;

use App\Models\Quotation;
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
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'telephone_number' => 'nullable|string',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $channels = $this->input('channels');
            if (is_null($channels)) {
                $channels = ['email'];
            }
            if (!is_array($channels)) {
                return;
            }

            $quotationId = $this->route('id') ?? $this->route('quotation');
            $quotation = $quotationId ? Quotation::with(['patient', 'labClient'])->find($quotationId) : null;

            $email = $this->input('email') ?: $quotation?->customer_email;
            $phone = $this->input('phone')
                ?? $this->input('phone_number')
                ?? $this->input('telephone_number')
                ?? $quotation?->customer_phone;

            if (in_array('email', $channels)) {
                if (empty($email)) {
                    $validator->errors()->add('email', 'An email address is required when sending via email.');
                }
            }

            if (in_array('whatsapp', $channels)) {
                if (empty($phone)) {
                    $validator->errors()->add('phone', 'A phone number is required when sending via WhatsApp.');
                }
            }

            if (in_array('sms', $channels)) {
                if (empty($phone)) {
                    $validator->errors()->add('telephone_number', 'A telephone number is required when sending via SMS.');
                }
            }
        });
    }
}
