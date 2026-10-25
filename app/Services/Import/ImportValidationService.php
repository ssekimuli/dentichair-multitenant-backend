<?php

namespace App\Services\Import;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    public function normalizeRow(array $mappedData, string $table, $user = null, ?int $branchId = null, array $rawData = []): array
    {
        $table = $this->schemaService->normalizeTableName($table);

        // Special lookup for medical_histories and invoices: resolve patient by contact/phone
        if ($table === 'medical_histories' || $table === 'invoices') {
            $mappedData = $this->resolveMedicalHistoryPatient($mappedData, $branchId, $rawData, $table);
        }

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

            // Normalize phone/contact fields (e.g. Excel trimmed 700111222 -> 0700111222)
            if (($colName === 'phone' || $colName === 'contact') && is_scalar($value)) {
                $value = $this->normalizePhoneNumber((string)$value);
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
                        // FIX: spreadsheet readers return "27.0"; stripping non-digits would give 270.
                        if (is_numeric($value)) {
                            $value = (int) round((float) $value);
                        } else {
                            $clean = preg_replace('/[^\d\-]/', '', (string)$value);
                            $value = ($clean !== '') ? (int)$clean : null;
                        }
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

        // Multi-tenant isolation: enforce selected branch or fallback to user branch context
        if ($tenantColumn) {
            if ($branchId) {
                $normalized[$tenantColumn] = $branchId;
            } elseif (isset($mappedData[$tenantColumn]) && $mappedData[$tenantColumn] !== null && $mappedData[$tenantColumn] !== '') {
                $normalized[$tenantColumn] = (int)$mappedData[$tenantColumn];
            } elseif ($user && isset($user->{$tenantColumn})) {
                $normalized[$tenantColumn] = $user->{$tenantColumn};
            }
        }

        if (isset($mappedData['_unresolved_patient_contact'])) {
            $normalized['_unresolved_patient_contact'] = $mappedData['_unresolved_patient_contact'];
        }

        if (isset($mappedData['_patient_demographics'])) {
            $normalized['_patient_demographics'] = $mappedData['_patient_demographics'];
        }

        return $normalized;
    }

    /**
     * Resolve patient_id for medical_histories and invoices from contact / phone or raw row data.
     */
    protected function resolveMedicalHistoryPatient(array $mappedData, ?int $branchId = null, array $rawData = [], string $table = 'medical_histories'): array
    {
        // 1. Check mapped contact fields
        $contact = $mappedData['contact']
            ?? $mappedData['phone']
            ?? $mappedData['patient_contact']
            ?? $mappedData['patient_phone']
            ?? null;

        // 2. Check if patient_id is holding a phone number or non-numeric contact
        $patientIdVal = $mappedData['patient_id'] ?? null;
        if (empty($contact) && !empty($patientIdVal)) {
            $pStr = trim((string)$patientIdVal);
            $cleanDigits = preg_replace('/\D+/', '', $pStr);
            $isPhoneLike = str_starts_with($pStr, '+')
                || str_starts_with($pStr, '0')
                || str_starts_with($pStr, '256')
                || strlen($cleanDigits) >= 7
                || preg_match('/[\s\-]/', $pStr);

            if ($isPhoneLike) {
                // If numeric and already an existing patient ID, leave it as patient_id
                if (is_numeric($pStr) && strlen($pStr) <= 6 && DB::table('patients')->where('id', (int)$pStr)->exists()) {
                    // Valid existing patient ID
                } else {
                    $contact = $pStr;
                }
            }
        }

        // 3. Check unmapped raw data columns for contact / phone
        if (empty($contact) && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['contact', 'phone', 'telephone', 'mobile', 'patient contact', 'patient phone', 'patient_contact', 'patient_phone', 'cell phone', 'phone number', 'contact number', 'contacts', 'tel'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $contact = trim((string)$headerVal);
                        break;
                    }
                }
            }
        }

        // 4. Extract patient name from mapped or raw headers
        $patientName = $mappedData['patient_name'] ?? $mappedData['name'] ?? null;
        if (empty($patientName) && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['patient name', 'name', 'full name', 'patient_name', 'client name', 'client', 'patient', 'patient fullname'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $patientName = trim((string)$headerVal);
                        break;
                    }
                }
            }
        }

        // 5. Extract patient demographic fields (these belong to patients, not medical_histories)
        $demographics = [];
        $demoFields = ['age', 'gender', 'address'];
        foreach ($demoFields as $df) {
            if (!empty($mappedData[$df]) && is_scalar($mappedData[$df])) {
                $demographics[$df] = trim((string)$mappedData[$df]);
            }
        }

        // Also check raw data for unmapped demographic columns
        if (!empty($rawData)) {
            $demoAliases = [
                'age' => ['age', 'patient age', 'years old', 'years', 'yrs', 'patient_age'],
                'gender' => ['gender', 'sex', 'patient gender', 'patient sex', 'm/f'],
                'address' => ['address', 'residence', 'location', 'patient address', 'home address', 'area'],
            ];
            foreach ($demoAliases as $demoKey => $demoHeaders) {
                if (empty($demographics[$demoKey])) {
                    foreach ($rawData as $headerKey => $headerVal) {
                        if (in_array(strtolower(trim((string)$headerKey)), $demoHeaders, true)) {
                            if (!empty($headerVal) && is_scalar($headerVal)) {
                                $demographics[$demoKey] = trim((string)$headerVal);
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Normalize gender value to match enum('Male','Female','Other')
        if (!empty($demographics['gender'])) {
            $genderVal = strtolower($demographics['gender']);
            if (in_array($genderVal, ['m', 'male', 'boy', 'man'], true)) {
                $demographics['gender'] = 'Male';
            } elseif (in_array($genderVal, ['f', 'female', 'girl', 'woman'], true)) {
                $demographics['gender'] = 'Female';
            } elseif (in_array($genderVal, ['other', 'o', 'x', 'non-binary'], true)) {
                $demographics['gender'] = 'Other';
            }
        }

        // FIX: parse age numerically ("27.0" -> 27) instead of stripping non-digits ("27.0" -> 270)
        if (!empty($demographics['age'])) {
            $demographics['age'] = $this->parseAge($demographics['age']);

            if ($demographics['age'] !== null && $demographics['age'] > 0) {
                $demographics['date_of_birth'] = now()->subYears($demographics['age'])->format('Y-m-d');
            } else {
                unset($demographics['age']);
            }
        }

        if (!empty($demographics)) {
            $mappedData['_patient_demographics'] = $demographics;
        }

        // 6. Resolve patient_id: if existing valid ID, use it; otherwise find by phone/name or auto-create patient
        $currId = $mappedData['patient_id'] ?? null;
        if (!empty($currId) && is_numeric($currId) && DB::table('patients')->where('id', (int)$currId)->exists()) {
            $mappedData['patient_id'] = (int)$currId;
        } else {
            // Automatically find or create the patient so this record is ALWAYS imported
            $mappedData['patient_id'] = $this->findOrCreatePatientForMedicalHistory(
                $contact ? (string)$contact : null,
                $patientName ? (string)$patientName : null,
                $demographics,
                $branchId
            );
        }

        // For invoices: populate patient_name from linked patient if empty
        if ($table === 'invoices' && empty($mappedData['patient_name']) && !empty($mappedData['patient_id'])) {
            $pat = DB::table('patients')->where('id', (int)$mappedData['patient_id'])->first();
            if ($pat) {
                $mappedData['patient_name'] = trim("{$pat->first_name} {$pat->last_name}");
            }
        }

        // Clean up virtual contact, name, and demographic fields so they don't reach database insert
        unset(
            $mappedData['contact'], $mappedData['phone'],
            $mappedData['patient_contact'], $mappedData['patient_phone'],
            $mappedData['name'],
            $mappedData['age'], $mappedData['gender'], $mappedData['address'],
            $mappedData['_unresolved_patient_contact']
        );

        if ($table === 'medical_histories') {
            unset($mappedData['patient_name']);
        }

        return $mappedData;
    }

    /**
     * Resilient lookup or automatic on-the-fly creation of patient for medical history import.
     */
    public function findOrCreatePatientForMedicalHistory(
        ?string $contact,
        ?string $patientName,
        array $demographics,
        ?int $branchId = null
    ): int {
        // 1. If contact exists, try resilient phone lookup first
        if (!empty($contact)) {
            $patient = $this->findPatientByPhone($contact, $branchId);
            if ($patient) {
                return (int)$patient->id;
            }
        }

        // 2. Determine first and last names
        $firstName = 'Imported';
        $lastName = 'Patient';

        if (!empty($patientName)) {
            $cleanName = trim($patientName);
            if (str_contains($cleanName, ' ')) {
                $parts = preg_split('/\s+/', $cleanName, 2);
                $firstName = !empty($parts[0]) ? trim($parts[0]) : 'Imported';
                $lastName = !empty($parts[1]) ? trim($parts[1]) : 'Patient';
            } else {
                $firstName = $cleanName;
                $lastName = 'Patient';
            }

            // Lookup existing patient by name in branch
            $nameQuery = DB::table('patients')
                ->where('first_name', $firstName)
                ->where('last_name', $lastName);
            if ($branchId) {
                $nameQuery->where('branch_id', $branchId);
            }
            $existingByName = $nameQuery->first();
            if ($existingByName) {
                return (int)$existingByName->id;
            }
        } elseif (!empty($contact)) {
            $digits = preg_replace('/\D+/', '', $this->stripFloatSuffix($contact));
            $firstName = 'Patient';
            $lastName = (strlen($digits) >= 4) ? substr($digits, -4) : 'Imported';
        }

        // 3. Resolve target branch
        $targetBranchId = $branchId ?: 1;
        if (!DB::table('branches')->where('id', $targetBranchId)->exists()) {
            $targetBranchId = (int)(DB::table('branches')->value('id') ?? 1);
        }

        // 4. Determine DOB & age (FIX: validated parse, never derive a DOB from an out-of-range age)
        $age = !empty($demographics['age']) ? $this->parseAge($demographics['age']) : null;
        $dob = $demographics['date_of_birth']
            ?? ($age ? now()->subYears($age)->format('Y-m-d') : '1999-01-01');

        $gender = $demographics['gender'] ?? 'Other';
        $address = $demographics['address'] ?? null;

        // 5. Create new patient record
        $now = now();
        $patientData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $this->normalizePhoneNumber($contact),
            'email' => null,
            'gender' => in_array($gender, ['Male', 'Female', 'Other']) ? $gender : 'Other',
            'date_of_birth' => $dob,
            'age' => $age,
            'address' => $address,
            'status' => 'Active',
            'branch_id' => $targetBranchId,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('patients', 'client_uuid')) {
            $patientData['client_uuid'] = (string) Str::uuid();
        }

        return (int) DB::table('patients')->insertGetId($patientData);
    }

    /**
     * Parse an age value into a sane integer, or null.
     * Handles 27, "27", 27.0, "27.0", "27 yrs". Rejects values outside 0-120.
     */
    protected function parseAge($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!preg_match('/\d+(?:\.\d+)?/', (string)$value, $m)) {
            return null;
        }

        $age = (int) round((float) $m[0]);

        return ($age >= 0 && $age <= 120) ? $age : null;
    }

    /**
     * Remove the ".0" suffix that spreadsheet readers add to whole-number cells
     * (e.g. "700111222.0" -> "700111222").
     */
    protected function stripFloatSuffix(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d+)\.0+$/', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    /**
     * Canonicalize phone numbers to standard 10-digit local format with leading zero.
     * E.g. "700111222" -> "0700111222", "256700111222" -> "0700111222".
     */
    public function normalizePhoneNumber(?string $phoneVal): ?string
    {
        if (empty($phoneVal)) {
            return null;
        }

        // FIX: "700111222.0" must not become "7001112220"
        $raw = $this->stripFloatSuffix($phoneVal);
        $digits = preg_replace('/\D+/', '', $raw);

        // 9 digits without leading zero (e.g. 700111222 -> 0700111222)
        if (strlen($digits) === 9 && in_array($digits[0], ['7', '3', '4'], true)) {
            return '0' . $digits;
        }

        // 12 digits starting with 256 (e.g. 256700111222 -> 0700111222)
        if (strlen($digits) === 12 && str_starts_with($digits, '256')) {
            return '0' . substr($digits, 3);
        }

        return $raw;
    }

    /**
     * Resilient lookup for a patient by phone number across standard Ugandan & international formats.
     */
    public function findPatientByPhone(string $phoneVal, ?int $branchId = null): ?object
    {
        $raw = $this->stripFloatSuffix($phoneVal);
        $digits = preg_replace('/\D+/', '', $raw);

        if (strlen($digits) < 6) {
            return null;
        }

        $candidates = [$raw, $digits];

        $last9 = null;
        if (strlen($digits) >= 9) {
            $last9 = substr($digits, -9);
            $candidates[] = $last9;
            $candidates[] = '0' . $last9;
            $candidates[] = '+256' . $last9;
            $candidates[] = '256' . $last9;
            $candidates[] = '0' . substr($last9, 0, 3) . ' ' . substr($last9, 3, 3) . ' ' . substr($last9, 6);
            $candidates[] = '+256 ' . substr($last9, 0, 3) . ' ' . substr($last9, 3);
        }
        $candidates = array_unique(array_filter($candidates));

        $buildPhoneQuery = function () use ($candidates, $last9) {
            return DB::table('patients')->where(function ($q) use ($candidates, $last9) {
                $q->whereIn('phone', $candidates);
                if ($last9) {
                    $q->orWhere('phone', 'LIKE', '%' . $last9);
                }
            });
        };

        if ($branchId) {
            $branchMatch = $buildPhoneQuery()->where('branch_id', $branchId)->first();
            if ($branchMatch) {
                return $branchMatch;
            }
        }

        return $buildPhoneQuery()->first();
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

        $validator = Validator::make($normalizedRow, $rules, [
            'patient_id.required' => 'Patient could not be identified. Please provide a valid patient ID or registered contact number.',
            'patient_id.exists' => 'No patient found with the provided contact number or ID in the system.',
        ], $attributes);

        $errors = [];

        // Check if an unresolved contact was recorded during normalization
        if (!empty($normalizedRow['_unresolved_patient_contact'])) {
            $contact = $normalizedRow['_unresolved_patient_contact'];
            $sourceColumn = $fieldToHeaderMap['patient_id'] ?? $fieldToHeaderMap['contact'] ?? 'Contact';
            $errors[] = [
                'row_number' => $rowNumber,
                'source_column' => $sourceColumn,
                'destination_field' => 'patient_id',
                'error_code' => 'PATIENT_NOT_FOUND',
                'error_message' => "Patient with contact '{$contact}' was not found in the patients table. Please ensure the patient is registered or imported into Patients first.",
            ];
            unset($normalizedRow['_unresolved_patient_contact']);
        }

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                if ($field === 'patient_id' && !empty($errors)) {
                    continue;
                }
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