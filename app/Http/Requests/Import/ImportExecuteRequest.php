<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;

class ImportExecuteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = Config::get('import.max_file_size_kb', 102400);

        return [
            'table' => 'required|string|max:100',
            'import_id' => 'required_without:file|nullable|string|uuid',
            'file' => [
                'required_without:import_id',
                'nullable',
                'file',
                "max:{$maxKb}",
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $ext = strtolower($value->getClientOriginalExtension());
                        $allowed = Config::get('import.allowed_extensions', ['csv', 'txt', 'xlsx', 'xls']);
                        if (!in_array($ext, $allowed, true)) {
                            $fail("The {$attribute} must be a file of type: " . implode(', ', $allowed) . '.');
                        }
                    }
                },
            ],
            'mapping' => 'required|array|min:1',
            'mode' => 'nullable|string|in:insert,skip_duplicates,update_existing,upsert',
            'error_mode' => 'nullable|string|in:atomic,partial',
            'update_key' => 'nullable|string|max:100',
            'sheet' => 'nullable|string|max:100',
            'async' => 'nullable|boolean',
            'options' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'table.required' => 'The destination table identifier is required.',
            'mapping.required' => 'A valid column mapping object is required.',
            'import_id.required_without' => 'An import_id from preview or an uploaded file must be provided.',
        ];
    }
}
