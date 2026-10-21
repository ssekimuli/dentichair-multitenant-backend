<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

class ImportPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = Config::get('import.max_file_size_kb', 102400);

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    $allowed = Config::get('import.allowed_extensions', ['csv', 'txt', 'xlsx', 'xls']);
                    if (!in_array($ext, $allowed, true)) {
                        $fail("The {$attribute} must be a file of type: " . implode(', ', $allowed) . '.');
                    }
                },
            ],
            'table' => 'required|string|max:100',
            'sheet' => 'nullable|string|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ];
    }

    public function messages(): array
    {
        return [
            'table.required' => 'The destination table identifier is required.',
            'file.required' => 'A spreadsheet file (CSV, XLSX, XLS) is required for import preview.',
        ];
    }
}
