<?php

namespace App\Services\Import;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportValidationService
{
    protected ImportSchemaService $schemaService;

    public function __construct(ImportSchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
    }

    /**
     * Normalize raw row data according to destination column types and defaults.
     */
    public function normalizeRow(array $mappedData, string $table, $user = null): array
    {
        $columns = $this->schemaService->getImportableColumns($table);
        $tableConfig = $this->schemaService->getTableConfig($table) ?? [];
        $defaults = $tableConfig['defaults'] ?? [];
        $tenantColumn = $tableConfig['tenant_column'] ?? null;

        $normalized = [];

        // Resolve common destination aliases if raw alias keys were passed in mappedData
        if (!isset($mappedData['last_visit']) && isset($mappedData['date']) && !isset($columns['date']) && isset($columns['last_visit'])) {
            $mappedData['last_visit'] = $mappedData['date'];
        }
        if (!isset($mappedData['first_name']) && isset($mappedData['name']) && !isset($columns['name']) && isset($columns['first_name'])) {
            $mappedData['first_name'] = $mappedData['name'];
        }
        if (!isset($mappedData['phone']) && isset($mappedData['contact']) && !isset($columns['contact']) && isset($columns['phone'])) {
            $mappedData['phone'] = $mappedData['contact'];
        }
        if (!isset($mappedData['history']) && isset($mappedData['procedure']) && !isset($columns['procedure']) && isset($columns['history'])) {
            $mappedData['history'] = $mappedData['procedure'];
        }

        foreach ($columns as $colName => $colMeta) {
            $value = $mappedData[$colName] ?? null;

            // Trim strings
            if (is_string($value)) {
                $value = trim($value);
            }

            // Apply defaults if empty
            if (($value === null || $value === '') && array_key_exists($colName, $defaults)) {
                $value = $defaults[$colName];
            }

            // Convert empty string to null for nullable columns
            if ($value === '' && $colMeta['nullable']) {
                $value = null;
            }

            // Type-specific normalization
            if ($value !== null && $value !== '') {
                switch ($colMeta['type']) {
                    case 'integer':
                        $clean = preg_replace('/[^\d\-]/', '', (string)$value);
                        $value = ($clean !== '') ? (int)$clean : null;
                        break;

                    case 'decimal':
                        // Strip currency signs and thousand separators
                        $clean = str_replace([',', '$', '€', '£', 'UGX', ' '], '', (string)$value);
                        $value = is_numeric($clean) ? (float)$clean : $value;
                        break;

                    case 'boolean':
                        $valLower = strtolower((string)$value);
                        if (in_array($valLower, ['true', '1', 'yes', 'y', 'active'], true)) {
                            $value = 1;
                        } elseif (in_array($valLower, ['false', '0', 'no', 'n', 'inactive'], true)) {
                            $value = 0;
                        }
                        break;

                    case 'date':
                        $value = $this->parseDateValue($value);
                        break;

                    case 'datetime':
                        $value = $this->parseDateTimeValue($value);
                        break;

                    case 'json':
                        if (is_array($value)) {
                            $value = json_encode($value);
                        } elseif (is_string($value) && !str_starts_with($value, '{') && !str_starts_with($value, '[')) {
                            $value = json_encode([$value]);
                        }
                        break;
                }
            }

            $normalized[$colName] = $value;
        }

        // Smart name splitting: If table has both first_name and last_name, and first_name contains multiple words (e.g. "Ludigo Stephane"),
        // while last_name was not explicitly supplied in the import file, split them automatically.
        if (isset($normalized['first_name']) && is_string($normalized['first_name']) && isset($columns['last_name'])) {
            $firstNameVal = trim($normalized['first_name']);
            $lastNameVal = $mappedData['last_name'] ?? null;
            $defaultLastName = $defaults['last_name'] ?? 'Patient';

            if ((empty($lastNameVal) || ($normalized['last_name'] ?? null) === $defaultLastName) && str_contains($firstNameVal, ' ')) {
                $parts = preg_split('/\s+/', $firstNameVal, 2);
                if (count($parts) === 2 && !empty($parts[0]) && !empty($parts[1])) {
                    $normalized['first_name'] = $parts[0];
                    $normalized['last_name'] = $parts[1];
                }
            }
        }

        // Multi-tenant isolation: always enforce tenant context (e.g. branch_id)
        if ($tenantColumn && $user && isset($user->{$tenantColumn})) {
            $normalized[$tenantColumn] = $user->{$tenantColumn};
        }

        return $normalized;
    }

    /**
     * Validate normalized row against schema rules and custom table rules.
     */
    public function validateRow(
        array $normalizedRow,
        int $rowNumber,
        string $table,
        array $fieldToHeaderMap = []
    ): array {
        $columns = $this->schemaService->getImportableColumns($table);
        $tableConfig = $this->schemaService->getTableConfig($table) ?? [];
        $customRules = $tableConfig['custom_rules'] ?? [];

        $rules = [];
        $attributes = [];

        foreach ($columns as $colName => $colMeta) {
            $colRules = [];

            // If a custom rule is specified in config, use that
            if (isset($customRules[$colName])) {
                $rules[$colName] = $customRules[$colName];
                $attributes[$colName] = $colMeta['label'];
                continue;
            }

            if ($colMeta['required']) {
                $colRules[] = 'required';
            } else {
                $colRules[] = 'nullable';
            }

            switch ($colMeta['type']) {
                case 'integer':
                    $colRules[] = 'integer';
                    break;
                case 'decimal':
                    $colRules[] = 'numeric';
                    break;
                case 'boolean':
                    $colRules[] = 'boolean';
                    break;
                case 'date':
                    $colRules[] = 'date';
                    break;
                case 'datetime':
                    $colRules[] = 'date';
                    break;
                case 'json':
                    $colRules[] = 'json';
                    break;
                case 'string':
                case 'text':
                    $colRules[] = 'string';
                    break;
            }

            // Foreign key validation
            if (!empty($colMeta['foreign_key'])) {
                $fk = $colMeta['foreign_key'];
                $colRules[] = "exists:{$fk['foreign_table']},{$fk['foreign_column']}";
            }

            $rules[$colName] = implode('|', $colRules);
            $attributes[$colName] = $colMeta['label'];
        }

        $validator = Validator::make($normalizedRow, $rules, [], $attributes);

        if ($validator->passes()) {
            return [];
        }

        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $sourceColumn = $fieldToHeaderMap[$field] ?? $field;
            foreach ($messages as $msg) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'source_column' => $sourceColumn,
                    'destination_field' => $field,
                    'error_code' => 'VALIDATION_FAILED',
                    'error_message' => $msg,
                ];
            }
        }

        return $errors;
    }

    /**
     * Safely parse human or spreadsheet date strings into Y-m-d.
     */
    protected function parseDateValue($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Check if numeric Excel serial date (e.g. 44561)
        if (is_numeric($value) && (int)$value > 1000 && (int)$value < 60000) {
            try {
                // Excel base date is 1899-12-30
                $excelBase = Carbon::create(1899, 12, 30);
                return $excelBase->addDays((int)$value)->format('Y-m-d');
            } catch (\Throwable $e) {
                // Fall through
            }
        }

        // Try standard formats
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'd-m-Y', 'm-d-Y', 'd.m.Y', 'Y.m.d'];
        foreach ($formats as $fmt) {
            try {
                $parsed = Carbon::createFromFormat($fmt, (string)$value);
                if ($parsed && $parsed->year > 1900 && $parsed->year < 2100) {
                    return $parsed->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Fallback to strtotime / Carbon::parse
        try {
            $parsed = Carbon::parse((string)$value);
            return $parsed->format('Y-m-d');
        } catch (\Throwable $e) {
            return (string)$value; // Return original for validator to report as invalid date
        }
    }

    /**
     * Safely parse human or spreadsheet datetime strings into Y-m-d H:i:s.
     */
    protected function parseDateTimeValue($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse((string)$value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return (string)$value;
        }
    }
}
