<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLabServicePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lab_service_id' => 'required|exists:lab_services,id',
            'lab' => 'required|string|max:255',
            'base_price' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ];
    }
}
