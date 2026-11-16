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

        // Special lookup for medical_histories, invoices, and laboratory_orders
        if ($table === 'medical_histories') {
            $mappedData = $this->resolveMedicalHistoryPatient($mappedData, $branchId, $rawData, $table);
        } elseif ($table === 'invoices') {
            $mappedData = $this->resolveInvoicePatient($mappedData, $branchId, $rawData);
        } elseif ($table === 'laboratory_orders') {
            $mappedData = $this->resolveLabOrderClient($mappedData, $branchId, $rawData);
        } elseif ($table === 'appointments') {
            $mappedData = $this->resolveAppointmentPatient($mappedData, $branchId, $rawData);
        }

        $columns = $this->schemaService->getImportableColumns($table);
        $tableConfig = $this->schemaService->getTableConfig($table) ?? [];
        $defaults = $tableConfig['defaults'] ?? [];
        $tenantColumn = $tableConfig['tenant_column'] ?? null;

        // "Adult" / "Child" in Date of Birth: store the age (Adult 18, child under 18) and
        // set the date of birth to today minus that age, as the app does for age-only records.
        if ($table === 'patients' && isset($mappedData['date_of_birth']) && is_string($mappedData['date_of_birth'])) {
            $wordAge = $this->ageForDateOfBirthWord($mappedData['date_of_birth']);
            if ($wordAge !== null) {
                if (empty($mappedData['age'])) {
                    $mappedData['age'] = $wordAge;
                }
                $mappedData['date_of_birth'] = now()->subYears($wordAge)->format('Y-m-d');
            }
        }

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

            // Normalize appointment time to "10:00 AM" (e.g. "10:00 (30min)" -> "10:00 AM")
            if ($table === 'appointments' && $colName === 'time' && is_string($value) && $value !== '') {
                $value = $this->parseAppointmentTime($value) ?? $value;
            }

            // Normalize gender to Male / Female / Other (e.g. M, f, woman -> Male, Female, Female)
            if ($colName === 'gender' && is_string($value) && $value !== '') {
                $value = $this->normalizeGender($value);
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
                        // Invoice dates and patient dates of birth use the stricter day/month parser
                        $value = ($table === 'invoices' || $colName === 'date_of_birth')
                            ? $this->parseInvoiceDate($value)
                            : $this->parseDateValue($value);
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

        foreach ($mappedData as $k => $v) {
            if (str_starts_with((string)$k, '_')) {
                $normalized[$k] = $v;
            }
        }

        if ($table === 'invoices') {
            $amount = isset($normalized['amount']) && is_numeric($normalized['amount']) ? (float)$normalized['amount'] : 0.0;
            $paid = isset($normalized['paid_amount']) && is_numeric($normalized['paid_amount']) ? (float)$normalized['paid_amount'] : 0.0;
            $balance = max(0, $amount - $paid);

            $normalized['patient_responsibility'] = $amount;
            $normalized['patient_paid_amount'] = $paid;
            $normalized['patient_balance'] = $balance;

            // If the imported invoice still has a balance after making calculation, make status Pending
            if ($balance > 0) {
                $normalized['status'] = 'Pending';
            } elseif ($amount > 0 && $balance <= 0) {
                $normalized['status'] = 'Paid';
            } elseif (empty($normalized['status'])) {
                $normalized['status'] = 'Paid';
            }
        }

        return $normalized;
    }

    /**
     * Resolve patient for appointments from the Contact / Phone column.
     * If an existing patient matches the phone number, link the appointment to that patient
     * (and fill patient_name when the file did not supply one). Patients are never created here.
     * If no patient matches, patient_id stays null so the row fails validation with a clear message.
     */
    protected function resolveAppointmentPatient(array $mappedData, ?int $branchId = null, array $rawData = []): array
    {
        $contact = $mappedData['contact']
            ?? $mappedData['phone']
            ?? $mappedData['patient_contact']
            ?? $mappedData['patient_phone']
            ?? null;

        // Fall back to an unmapped raw phone column in the file
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

        $matchedPatient = null;

        // An email in the contact column, or a separate email column, identifies the patient too
        $email = null;
        if (!empty($contact) && str_contains((string)$contact, '@')) {
            $email = trim((string)$contact);
            $contact = null;
        }
        if ($email === null) {
            $email = $mappedData['email'] ?? $mappedData['patient_email'] ?? null;
        }
        if (empty($email) && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['email', 'e-mail', 'patient email', 'patient_email', 'email address'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $email = trim((string)$headerVal);
                        break;
                    }
                }
            }
        }

        // An explicit, existing patient ID mapped to patient_id takes priority
        $patientIdVal = $mappedData['patient_id'] ?? null;
        if (is_numeric($patientIdVal) && DB::table('patients')->where('id', (int)$patientIdVal)->exists()) {
            $matchedPatient = DB::table('patients')->where('id', (int)$patientIdVal)->first();
        } elseif (!empty($contact)) {
            // Name is used too, so a relative sharing the phone number is not linked by mistake
            $match = $this->matchPatientByPhone((string)$contact, $branchId, $mappedData['patient_name'] ?? null);
            $matchedPatient = $match['patient'];
            if ($match['ambiguous']) {
                $mappedData['_ambiguous_patient'] = (string)$contact;
            }
        }

        // No phone match: try the email, scoped to the branch first
        if (!$matchedPatient && !empty($email)) {
            $emailQuery = DB::table('patients')->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
            $matchedPatient = ($branchId ? (clone $emailQuery)->where('branch_id', $branchId)->first() : null)
                ?? $emailQuery->first();
        }

        if ($matchedPatient) {
            $mappedData['patient_id'] = (int)$matchedPatient->id;
            if (empty($mappedData['patient_name'])) {
                $mappedData['patient_name'] = trim("{$matchedPatient->first_name} {$matchedPatient->last_name}");
            }
        } else {
            $mappedData['patient_id'] = null;
        }

        // Contact is only used for lookup; it is not a column on appointments
        unset(
            $mappedData['contact'], $mappedData['phone'],
            $mappedData['patient_contact'], $mappedData['patient_phone']
        );

        return $mappedData;
    }

    /**
     * Resolve patient for invoices from contact/phone.
     * If matching with an existing patient, link invoice to that patient by getting patient_id.
     * If not matched (or contact missing), DO NOT error and DO NOT create a patient;
     * set patient_id = null and import the invoice cleanly.
     */
    protected function resolveInvoicePatient(array $mappedData, ?int $branchId = null, array $rawData = []): array
    {
        // 1. Extract contact from mapped data
        $contact = $mappedData['contact']
            ?? $mappedData['phone']
            ?? $mappedData['patient_contact']
            ?? $mappedData['patient_phone']
            ?? null;

        // 2. Check if patient_id is holding an existing patient ID or a phone/contact string
        $patientIdVal = $mappedData['patient_id'] ?? null;
        $explicitPatientId = null;

        if (!empty($patientIdVal)) {
            $pStr = trim((string)$patientIdVal);
            $cleanDigits = preg_replace('/\D+/', '', $pStr);
            $isPhoneLike = str_starts_with($pStr, '+')
                || str_starts_with($pStr, '0')
                || str_starts_with($pStr, '256')
                || strlen($cleanDigits) >= 7
                || preg_match('/[\s\-]/', $pStr);

            if ($isPhoneLike) {
                // If numeric, short, and already an existing patient ID, use it directly
                if (is_numeric($pStr) && strlen($pStr) <= 6 && DB::table('patients')->where('id', (int)$pStr)->exists()) {
                    $explicitPatientId = (int)$pStr;
                } else {
                    if (empty($contact)) {
                        $contact = $pStr;
                    }
                }
            } elseif (is_numeric($pStr) && DB::table('patients')->where('id', (int)$pStr)->exists()) {
                $explicitPatientId = (int)$pStr;
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

        // 5. Extract treatment / procedure if present (e.g. from TREATMENT column)
        $treatment = $mappedData['procedure'] ?? $mappedData['treatment'] ?? null;
        if (empty($treatment) && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['treatment', 'procedure', 'treatment name', 'procedure name', 'service', 'item'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $treatment = trim((string)$headerVal);
                        break;
                    }
                }
            }
        }
        if (!empty($treatment)) {
            $mappedData['_invoice_treatment'] = (string)$treatment;
        }

        // 6. Check contact matching with patient
        $matchedPatient = null;
        if ($explicitPatientId) {
            $matchedPatient = DB::table('patients')->where('id', $explicitPatientId)->first();
        } elseif (!empty($contact)) {
            // Name is used too, so a relative sharing the phone number is not billed by mistake
            $match = $this->matchPatientByPhone((string)$contact, $branchId, $patientName ?: null);
            $matchedPatient = $match['patient'];
            if ($match['ambiguous']) {
                $mappedData['_ambiguous_patient'] = (string)$contact;
            }
        }

        if ($matchedPatient) {
            // Match found: link that invoice to that patient by getting that patient ID
            $mappedData['patient_id'] = (int)$matchedPatient->id;
            if (empty($patientName)) {
                $mappedData['patient_name'] = trim("{$matchedPatient->first_name} {$matchedPatient->last_name}");
            } else {
                $mappedData['patient_name'] = $patientName;
            }
        } else {
            // Not matched: DO NOT error, DO NOT auto-create patient.
            // Just import the record with patient_id = null without showing any error!
            $mappedData['patient_id'] = null;
            if (!empty($patientName)) {
                $mappedData['patient_name'] = $patientName;
            }
        }

        // Clean up virtual columns so they don't break MySQL insert
        unset(
            $mappedData['contact'], $mappedData['phone'],
            $mappedData['patient_contact'], $mappedData['patient_phone'],
            $mappedData['name'],
            $mappedData['procedure'], $mappedData['treatment'],
            $mappedData['age'], $mappedData['gender'], $mappedData['address'],
            $mappedData['_unresolved_patient_contact']
        );

        return $mappedData;
    }

    /**
     * Resolve client for laboratory_orders from client_name / contact, auto-creating if missing,
     * and capture total_amount / paid_amount for invoice creation.
     */
    public function resolveLabOrderClient(array $mappedData, ?int $branchId = null, array $rawData = []): array
    {
        // 1. Extract explicit client_id or check if client_id contains a client name
        $explicitClientId = null;
        if (isset($mappedData['client_id'])) {
            $val = trim((string)$mappedData['client_id']);
            if (is_numeric($val) && (int)$val > 0) {
                $explicitClientId = (int)$val;
            } else {
                if (empty($mappedData['client_name']) && $val !== '') {
                    $mappedData['client_name'] = $val;
                }
                unset($mappedData['client_id']);
            }
        }

        // 2. Extract client phone / contact
        $clientPhone = $mappedData['client_phone']
            ?? $mappedData['client_contact']
            ?? $mappedData['phone']
            ?? null;

        if (empty($clientPhone) && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['client phone', 'client_phone', 'lab phone', 'lab_phone', 'client contact', 'client_contact', 'lab contact', 'lab_contact', 'contact', 'phone'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $clientPhone = trim((string)$headerVal);
                        break;
                    }
                }
            }
        }

        // 3. Extract client email
        $clientEmail = $mappedData['client_email']
            ?? $mappedData['email']
            ?? null;

        if (empty($clientEmail) && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['client email', 'client_email', 'lab email', 'lab_email', 'email'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $clientEmail = trim((string)$headerVal);
                        break;
                    }
                }
            }
        }

        // 4. Extract client name
        $clientName = $mappedData['client_name']
            ?? $mappedData['client']
            ?? $mappedData['lab_name']
            ?? $mappedData['lab']
            ?? $mappedData['dental_lab']
            ?? null;

        if (empty($clientName) && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['client', 'client name', 'client_name', 'lab', 'lab name', 'lab_name', 'dental lab', 'dentallab', 'dental_lab', 'laboratory', 'vendor', 'supplier'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $clientName = trim((string)$headerVal);
                        break;
                    }
                }
            }
        }

        // 5. Check if client exists in `clients` table
        $matchedClient = null;
        if ($explicitClientId) {
            $matchedClient = DB::table('clients')->where('id', $explicitClientId)->first();
        }
        if (!$matchedClient && !empty($clientPhone)) {
            $matchedClient = $this->findClientByPhone((string)$clientPhone);
        }
        if (!$matchedClient && !empty($clientEmail)) {
            $matchedClient = DB::table('clients')->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim((string)$clientEmail))])->first();
        }
        if (!$matchedClient && !empty($clientName)) {
            $normName = strtolower(trim((string)$clientName));
            $matchedClient = DB::table('clients')->whereRaw('LOWER(TRIM(name)) = ?', [$normName])->first();
            if (!$matchedClient) {
                $matchedClient = DB::table('clients')->where('name', 'like', "%" . trim((string)$clientName) . "%")->first();
            }
        }

        if ($matchedClient) {
            $mappedData['client_id'] = (int)$matchedClient->id;
            $mappedData['_resolved_client_name'] = $matchedClient->name;
        } else {
            // Client does NOT exist: auto-create the client in `clients` table!
            $cName = !empty($clientName) ? trim((string)$clientName) : 'Dental Lab';
            $cPhone = !empty($clientPhone) ? $this->normalizePhoneNumber((string)$clientPhone) : null;
            $cEmail = !empty($clientEmail) ? strtolower(trim((string)$clientEmail)) : null;
            $cAddress = !empty($mappedData['address']) ? trim((string)$mappedData['address']) : null;

            $newClientId = DB::table('clients')->insertGetId([
                'name' => $cName,
                'phone' => $cPhone,
                'email' => $cEmail,
                'address' => $cAddress,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $mappedData['client_id'] = (int)$newClientId;
            $mappedData['_resolved_client_name'] = $cName;
        }

        // 6. Extract financial amounts: total_amount and paid_amount
        $totalAmount = null;
        foreach (['applied_price', 'total_amount', 'cost', 'amount', 'price', 'total'] as $k) {
            if (isset($mappedData[$k]) && is_scalar($mappedData[$k])) {
                $clean = str_replace([',', '$', 'UGX', ' '], '', (string)$mappedData[$k]);
                if (is_numeric($clean)) {
                    $totalAmount = (float)$clean;
                    break;
                }
            }
        }
        if ($totalAmount === null && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['total amount', 'total_amount', 'amount', 'cost', 'price', 'total', 'applied price', 'applied_price', 'fee', 'charge', 'billed amount'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $clean = str_replace([',', '$', 'UGX', ' '], '', (string)$headerVal);
                        if (is_numeric($clean)) {
                            $totalAmount = (float)$clean;
                            break;
                        }
                    }
                }
            }
        }
        $totalAmount = max(0, (float)($totalAmount ?? 0));
        $mappedData['applied_price'] = $totalAmount;
        $mappedData['_total_amount'] = $totalAmount;

        $paidAmount = null;
        foreach (['paid_amount', 'paid', 'amount_paid', 'advance'] as $k) {
            if (isset($mappedData[$k]) && is_scalar($mappedData[$k])) {
                $clean = str_replace([',', '$', 'UGX', ' '], '', (string)$mappedData[$k]);
                if (is_numeric($clean)) {
                    $paidAmount = (float)$clean;
                    break;
                }
            }
        }
        if ($paidAmount === null && !empty($rawData)) {
            foreach ($rawData as $headerKey => $headerVal) {
                $cleanKey = strtolower(trim((string)$headerKey));
                if (in_array($cleanKey, ['paid amount', 'paid_amount', 'paid', 'amount paid', 'payment', 'advance', 'paid total'], true)) {
                    if (!empty($headerVal) && is_scalar($headerVal)) {
                        $clean = str_replace([',', '$', 'UGX', ' '], '', (string)$headerVal);
                        if (is_numeric($clean)) {
                            $paidAmount = (float)$clean;
                            break;
                        }
                    }
                }
            }
        }
        $paidAmount = max(0, (float)($paidAmount ?? 0));
        $mappedData['_paid_amount'] = $paidAmount;

        // 7. Extract item / procedure
        if (empty($mappedData['item'])) {
            $item = $mappedData['service'] ?? $mappedData['procedure'] ?? $mappedData['treatment'] ?? $mappedData['description'] ?? null;
            if (empty($item) && !empty($rawData)) {
                foreach ($rawData as $hk => $hv) {
                    $chk = strtolower(trim((string)$hk));
                    if (in_array($chk, ['item', 'item name', 'item_name', 'service', 'service name', 'service_name', 'procedure', 'procedure name', 'treatment', 'description'], true)) {
                        if (!empty($hv) && is_scalar($hv)) {
                            $item = trim((string)$hv);
                            break;
                        }
                    }
                }
            }
            $mappedData['item'] = !empty($item) ? (string)$item : 'Lab Order';
        }

        // 8. Extract patient name and update instructions
        $patientName = $mappedData['patient_name'] ?? null;
        if (empty($patientName) && !empty($rawData)) {
            foreach ($rawData as $hk => $hv) {
                $chk = strtolower(trim((string)$hk));
                if (in_array($chk, ['patient', 'patient name', 'patient_name', 'patient full name'], true)) {
                    if (!empty($hv) && is_scalar($hv)) {
                        $patientName = trim((string)$hv);
                        break;
                    }
                }
            }
        }
        if (!empty($patientName)) {
            $mappedData['_patient_name'] = $patientName;
            $instructions = $mappedData['instructions'] ?? '';
            if (stripos($instructions, $patientName) === false) {
                $mappedData['instructions'] = empty($instructions) ? "Patient: {$patientName}" : "Patient: {$patientName} | {$instructions}";
            }
        }

        // 9. Tooth validation
        if (!empty($mappedData['tooth'])) {
            $rawTooth = trim((string)$mappedData['tooth']);
            if (preg_match('/^(\d+)/', $rawTooth, $m)) {
                $mappedData['tooth'] = (int)$m[1];
                if ($rawTooth !== (string)$m[1]) {
                    $instructions = $mappedData['instructions'] ?? '';
                    $rangeNote = "Tooth: {$rawTooth}";
                    $mappedData['instructions'] = empty($instructions) ? $rangeNote : ($instructions . " | " . $rangeNote);
                }
            } else {
                $mappedData['tooth'] = null;
                $instructions = $mappedData['instructions'] ?? '';
                $rangeNote = "Tooth: {$rawTooth}";
                $mappedData['instructions'] = empty($instructions) ? $rangeNote : ($instructions . " | " . $rangeNote);
            }
        }

        // 10. Status normalization to ENUM
        $rawStatus = strtolower(trim((string)($mappedData['status'] ?? '')));
        if (in_array($rawStatus, ['draft'], true)) {
            $normStatus = 'Draft';
        } elseif (in_array($rawStatus, ['in progress', 'in_progress', 'in production', 'in_production', 'production', 'processing'], true)) {
            $normStatus = 'In Production';
        } elseif (in_array($rawStatus, ['ready', 'ready for delivery', 'ready_for_delivery'], true)) {
            $normStatus = 'Ready for Delivery';
        } elseif (in_array($rawStatus, ['delivered', 'completed', 'done'], true)) {
            $normStatus = 'Delivered';
        } elseif (in_array($rawStatus, ['cancelled', 'canceled'], true)) {
            $normStatus = 'Cancelled';
        } else {
            $normStatus = 'Received';
        }
        $mappedData['status'] = $normStatus;

        // 11. Branch ID
        if (empty($mappedData['branch_id'])) {
            $mappedData['branch_id'] = $branchId ?: 1;
        }

        // 12. Doctor name
        if (empty($mappedData['doctor_name'])) {
            $mappedData['doctor_name'] = 'Attending Doctor';
        }

        // 13. Clean up virtual helper fields so they don't break MySQL insert
        unset(
            $mappedData['client_name'], $mappedData['client'], $mappedData['lab_name'], $mappedData['lab'], $mappedData['dental_lab'],
            $mappedData['client_phone'], $mappedData['client_contact'], $mappedData['phone'],
            $mappedData['client_email'], $mappedData['email'],
            $mappedData['total_amount'], $mappedData['cost'], $mappedData['amount'], $mappedData['price'],
            $mappedData['paid_amount'], $mappedData['paid'], $mappedData['amount_paid'],
            $mappedData['patient_name'], $mappedData['service'], $mappedData['procedure'], $mappedData['treatment'],
            $mappedData['address'], $mappedData['client_address']
        );

        return $mappedData;
    }

    /**
     * Resolve patient_id for medical_histories from contact / phone or raw row data.
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
            $demographics['gender'] = $this->normalizeGender((string)$demographics['gender']);
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
            $this->syncPatientDemographics((int)$currId, $demographics);
        } else {
            // Automatically find or create the patient so this record is ALWAYS imported
            $resolvedId = $this->findOrCreatePatientForMedicalHistory(
                $contact ? (string)$contact : null,
                $patientName ? (string)$patientName : null,
                $demographics,
                $branchId
            );
            if ($resolvedId === 0) {
                // Ambiguous: several patients share this contact and name. Flag the row instead of guessing.
                $mappedData['patient_id'] = null;
                $mappedData['_ambiguous_patient'] = (string)$contact;
            } else {
                $mappedData['patient_id'] = $resolvedId;
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

            // Keep the same parsed age on the history row that was written to the patient,
            // so both tables always hold the age from the import file.
            $importAge = $demographics['age'] ?? null;
            if ($importAge !== null && Schema::hasColumn('medical_histories', 'age')) {
                $mappedData['age'] = (int) $importAge;
            }
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
        // 1. If contact exists, look up by phone AND name, so relatives sharing a phone stay separate.
        //    Returns 0 when more than one patient fits; the caller flags that row.
        if (!empty($contact)) {
            $match = $this->matchPatientByPhone($contact, $branchId, $patientName ?: null);
            if ($match['ambiguous']) {
                return 0;
            }
            if ($match['patient']) {
                $this->syncPatientDemographics((int)$match['patient']->id, $demographics);
                return (int)$match['patient']->id;
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
                $this->syncPatientDemographics((int)$existingByName->id, $demographics);
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
     * Update an existing patient's age / date_of_birth from the imported row.
     * Skips the update when the stored DOB already yields the same age, so a
     * precise real DOB is not replaced by an age-derived estimate.
     */
    protected function syncPatientDemographics(int $patientId, array $demographics): void
    {
        $age = !empty($demographics['age']) ? $this->parseAge($demographics['age']) : null;

        if ($age === null || $age <= 0) {
            return;
        }

        $patient = DB::table('patients')->where('id', $patientId)->first();
        if (!$patient) {
            return;
        }

        $currentAgeFromDob = null;
        if (!empty($patient->date_of_birth)) {
            try {
                $currentAgeFromDob = Carbon::parse($patient->date_of_birth)->age;
            } catch (\Throwable $e) {
                $currentAgeFromDob = null;
            }
        }

        // Already consistent with the imported age: nothing to change
        if ((int)$patient->age === $age && $currentAgeFromDob === $age) {
            return;
        }

        DB::table('patients')->where('id', $patientId)->update([
            'age' => $age,
            'date_of_birth' => now()->subYears($age)->format('Y-m-d'),
            'updated_at' => now(),
        ]);
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

        // Drop bracketed notes such as "(D.A)" or "[mother]"; keep digit-only brackets like "(0700)"
        $raw = preg_replace_callback('/[\(\[]([^\)\]]*)[\)\]]/', function ($m) {
            return preg_match('/[A-Za-z]/', $m[1]) ? ' ' : $m[0];
        }, $raw);
        $raw = str_replace(['(', ')', '[', ']'], ' ', $raw);

        // Use the first phone number in the cell: "0701862015 / 0750123456" -> "0701862015",
        // "Tel: 0701-862-015" -> "0701-862-015". Stops at letters or any other separator.
        if (!preg_match('/\+?\d[\d\s\-\.]*\d|\+?\d/', $raw, $m)) {
            return null;
        }

        // Keep digits only: removes spaces, +, -, dots and any other symbols
        $digits = preg_replace('/\D+/', '', $m[0]);
        if ($digits === '') {
            return null;
        }

        // Local numbers are 10 digits; an extra trailing digit (e.g. "0701862015 2") is dropped
        if ($digits[0] === '0' && strlen($digits) > 10) {
            $digits = substr($digits, 0, 10);
        }

        // 9 digits without leading zero (e.g. 700111222 -> 0700111222)
        if (strlen($digits) === 9 && in_array($digits[0], ['7', '3', '4'], true)) {
            return '0' . $digits;
        }

        // 12 digits starting with 256 (e.g. 256700111222 -> 0700111222)
        if (strlen($digits) === 12 && str_starts_with($digits, '256')) {
            return '0' . substr($digits, 3);
        }

        // Other international numbers keep their "+" (e.g. +44 20 7946 0000 -> +442079460000)
        if (str_starts_with(trim($m[0]), '+')) {
            return '+' . $digits;
        }

        return $digits;
    }

    /**
     * Resilient lookup for a patient by phone number across standard Ugandan & international formats.
     */
    public function findPatientByPhone(string $phoneVal, ?int $branchId = null, ?string $name = null): ?object
    {
        return $this->matchPatientByPhone($phoneVal, $branchId, $name)['patient'];
    }

    /**
     * Phone lookup that also reports ambiguity. Returns ['patient' => object|null, 'ambiguous' => bool].
     * Ambiguous means more than one patient fits (same phone, and the name cannot tell them apart),
     * so no patient is chosen and the caller should flag the row for review.
     */
    public function matchPatientByPhone(string $phoneVal, ?int $branchId = null, ?string $name = null): array
    {
        $raw = $this->stripFloatSuffix($phoneVal);
        $digits = preg_replace('/\D+/', '', $raw);

        if (strlen($digits) < 6) {
            return ['patient' => null, 'ambiguous' => false];
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

        // Keep only patients whose name contains every word of the given name (order-insensitive),
        // so a relative sharing the phone number is not picked by mistake.
        $wanted = ($name !== null && trim($name) !== '') ? $this->nameWords($name) : null;
        $filter = function ($rows) use ($wanted) {
            if ($wanted === null) {
                return $rows->values();
            }
            return $rows->filter(function ($patient) use ($wanted) {
                $have = $this->nameWords(trim($patient->first_name . ' ' . $patient->last_name));
                return empty(array_diff($wanted, $have));
            })->values();
        };

        // Current branch first, then the whole table
        $rows = collect();
        if ($branchId) {
            $rows = $filter($buildPhoneQuery()->where('branch_id', $branchId)->get());
        }
        if ($rows->isEmpty()) {
            $rows = $filter($buildPhoneQuery()->get());
        }

        if ($rows->isEmpty()) {
            return ['patient' => null, 'ambiguous' => false];
        }
        if ($rows->count() > 1) {
            return ['patient' => null, 'ambiguous' => true];
        }

        return ['patient' => $rows->first(), 'ambiguous' => false];
    }

    /**
     * Lowercase name words for order-insensitive comparison. Punctuation and symbols are dropped
     * ("AHMED*" -> "ahmed") and honorifics are ignored ("MS IKHLAS" -> "ikhlas").
     */
    public function nameWords(string $name): array
    {
        static $honorifics = ['ms', 'mrs', 'mr', 'miss', 'dr', 'mama', 'mzee', 'sr', 'jr'];

        $words = preg_split('/[^\p{L}]+/u', mb_strtolower(trim($name))) ?: [];

        return array_values(array_filter(
            $words,
            fn ($word) => $word !== '' && !in_array($word, $honorifics, true)
        ));
    }

    /**
     * Resilient lookup for a client by phone number.
     */
    public function findClientByPhone(string $phoneVal): ?object
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

        $client = DB::table('clients')->whereIn('phone', $candidates)->first();
        if ($client) {
            return $client;
        }

        if ($last9) {
            return DB::table('clients')->where('phone', 'LIKE', '%' . $last9)->first();
        }

        return null;
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

        // Several patients share this contact and the name does not tell them apart: flag, do not guess
        if (!empty($normalizedRow['_ambiguous_patient'])) {
            $contact = $normalizedRow['_ambiguous_patient'];
            $errors[] = [
                'row_number' => $rowNumber,
                'source_column' => $fieldToHeaderMap['patient_id'] ?? $fieldToHeaderMap['contact'] ?? 'Contact',
                'destination_field' => 'patient_id',
                'error_code' => 'PATIENT_AMBIGUOUS',
                'error_message' => "More than one patient has contact '{$contact}' and this name. Add the patient ID to this row or correct the name, then import again.",
            ];
            unset($normalizedRow['_ambiguous_patient']);
        }

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
     * When a date of birth cell holds a life-stage word instead of a date, return the age to store.
     * Adult -> 18, child / minor -> 10, teen -> 15, infant / baby / toddler -> 2. Null for anything else.
     */
    public function ageForDateOfBirthWord(string $value): ?int
    {
        $word = strtolower(trim($value));

        if (in_array($word, ['adult', 'adults', 'senior', 'seniors', 'elderly'], true)) {
            return 18;
        }
        if (in_array($word, ['child', 'children', 'kid', 'kids', 'minor', 'minors'], true)) {
            return 10;
        }
        if (in_array($word, ['teen', 'teens', 'teenager', 'teenagers', 'youth'], true)) {
            return 15;
        }
        if (in_array($word, ['infant', 'infants', 'baby', 'babies', 'toddler', 'toddlers'], true)) {
            return 2;
        }

        return null;
    }

    /**
     * Extract the start time from an appointment time cell and format it as "hh:mm AM/PM".
     * Ignores notes such as "(30min)". Without AM/PM, hours 1-6 are read as PM (clinic hours),
     * 7-11 as AM, 12 as PM, and 13-23 as 24-hour time. Returns null when no time is found.
     */
    public function parseAppointmentTime(string $value): ?string
    {
        if (!preg_match('/(\d{1,2})[:.](\d{2})\s*([ap])\.?\s*m?\.?/i', $value, $m)
            && !preg_match('/(\d{1,2})[:.](\d{2})/', $value, $m)) {
            return null;
        }

        $hour = (int)$m[1];
        $minute = (int)$m[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $meridiem = isset($m[3]) && $m[3] !== '' ? strtoupper($m[3]) : null;

        if ($meridiem === null) {
            if ($hour === 0 || ($hour >= 13 && $hour <= 23)) {
                $meridiem = 'PM';
            } elseif ($hour === 12) {
                $meridiem = 'PM';
            } elseif ($hour >= 1 && $hour <= 6) {
                $meridiem = 'PM';
            } else {
                $meridiem = 'AM';
            }
        } else {
            $meridiem = $meridiem === 'A' ? 'AM' : 'PM';
        }

        if ($hour > 12) {
            $hour -= 12;
        }
        if ($hour === 0) {
            $hour = 12;
        }

        return sprintf('%02d:%02d %s', $hour, $minute, $meridiem);
    }

    /**
     * Convert common gender spellings and codes to Male, Female or Other.
     * Unrecognized values are returned unchanged so validation can report them.
     */
    public function normalizeGender(string $value): string
    {
        $clean = strtolower(trim($value));
        $clean = preg_replace('/\s+/', ' ', $clean);

        if (in_array($clean, ['m', 'male', 'man', 'men', 'boy', 'mr', 'he'], true)) {
            return 'Male';
        }

        if (in_array($clean, ['f', 'female', 'woman', 'women', 'girl', 'mrs', 'miss', 'ms', 'she'], true)) {
            return 'Female';
        }

        if (in_array($clean, ['other', 'o', 'x', 'non-binary', 'nonbinary', 'non binary', 'nb', 'prefer not to say'], true)) {
            return 'Other';
        }

        return trim($value);
    }

    /**
     * Convert any supported invoice date format to Y-m-d (e.g. 1995-05-12).
     *
     * Supported: 1995-05-12, 1995/05/12, 1995.05.12, 19950512, 12/05/1995, 12-05-1995,
     * 12.05.1995, 12 05 1995, 12/05/95, 12 May 1995, 12-May-1995, May 12, 1995,
     * "Fri, 12 May 1995", dates with a trailing time, and Excel serial numbers.
     * Numeric day/month/year values are read day-first (12/05/1995 = 12 May 1995).
     * Unparseable values are returned unchanged so the row validator reports them.
     */
    protected function parseInvoiceDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }

        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }

        // Excel serial date (days since 1899-12-30)
        if (preg_match('/^\d+(\.\d+)?$/', $str) && (float)$str >= 1000 && (float)$str < 60000 && strlen($str) < 8) {
            return Carbon::create(1899, 12, 30)->addDays((int)floor((float)$str))->format('Y-m-d');
        }

        // Drop weekday prefix ("Fri, ", "Sat ") and trailing time ("14:30", "2:05 pm")
        $str = preg_replace('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*[,.]?\s+/i', '', $str);
        $str = preg_replace('/[T\s]*\d{1,2}:\d{2}(:\d{2})?(\.\d+)?\s*([ap]\.?m\.?)?\s*$/i', '', $str);
        $str = trim($str);

        $str = $this->stripDateNoise($str);

        // Compact yyyymmdd
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $str, $m)) {
            return $this->validDateOrOriginal((int)$m[1], (int)$m[2], (int)$m[3], $value);
        }

        // Year first: 1995-05-12, 1995/05/12, 1995.05.12, 1995-5-12
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $str, $m)) {
            return $this->validDateOrOriginal((int)$m[1], (int)$m[2], (int)$m[3], $value);
        }

        // Day first numeric: 12/05/1995, 12-05-1995, 12.05.1995, 12 05 1995, 12/05/95
        if (preg_match('/^(\d{1,2})[-\/. ](\d{1,2})[-\/. ](\d{4}|\d{2})$/', $str, $m)) {
            $year = (int)$m[3];
            if (strlen($m[3]) === 2) {
                $year = $year >= 50 ? 1900 + $year : 2000 + $year;
            }
            return $this->validDateOrOriginal($year, (int)$m[2], (int)$m[1], $value);
        }

        // Month names: 12 May 1995, 12-May-1995, 12/May/1995, May 12, 1995, 12th May 1995
        try {
            $parsed = Carbon::parse(preg_replace('/[\/.\-]+/', ' ', $str));
            if ($parsed->year > 1900 && $parsed->year < 2100) {
                return $parsed->format('Y-m-d');
            }
        } catch (\Throwable $e) {
            // Fall through
        }

        return (string)$value;
    }

    /**
     * Remove wording that stops a date parsing: ordinal suffixes ("1st", "23rd", "4th"),
     * commas and extra spaces. "May 1st, 2026" -> "May 1 2026", "1st/11/2026" -> "1/11/2026".
     */
    protected function stripDateNoise(string $str): string
    {
        $str = preg_replace('/\b(\d{1,2})(?:st|nd|rd|th)\b/i', '$1', $str);
        $str = str_replace(',', ' ', $str);

        return trim(preg_replace('/\s+/', ' ', $str));
    }

    /**
     * Return a Y-m-d string for a real calendar date, otherwise the original value.
     */
    protected function validDateOrOriginal(int $year, int $month, int $day, $original): string
    {
        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            return (string)$original;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
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

        $value = $this->stripDateNoise((string)$value);

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