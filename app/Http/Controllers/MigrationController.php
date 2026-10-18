<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * MigrationController - Phase 1: Enhanced Error Handling
 *
 * This controller handles data migration from CSV files with comprehensive
 * error tracking, validation, and detailed feedback to users.
 */
class MigrationController extends Controller
{
    /**
     * Maps clinical (procedure) row 'ref' values to the inserted treatment_plans
     * row so a later financial (invoice) row in the same import can attach it
     * as an invoice_treatments line via a 'procedure_refs' column.
     */
    private array $clinicalRefMap = [];

    /**
     * Maps financial (invoice) row 'ref' values to the inserted invoice, so a
     * later payment row in the same import can attach a payment_transactions
     * row to it via an 'invoice_ref' column.
     */
    private array $financialRefMap = [];

    public function migrate(Request $request)
    {
        try {
        set_time_limit(0);
        $startTime = microtime(true);
        $importId = 'imp_' . uniqid();
        $this->clinicalRefMap = [];
        $this->financialRefMap = [];

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx',
            'section' => 'required|string|in:employees,patients,appointments,clinical,financial,invoice,invoices,insurance,claim,client,clients,lab_order,lab_orders,medical_history,payroll,leave_request,staff_advance,insurance_company,insurance_package,insurance_coverage,insurance_policy,odontogram,periodontal,orthodontic,endodontic,payment'
        ]);

        $section = $request->input('section');
        $file = $request->file('file');

        $originalExtension = strtolower($file->getClientOriginalExtension());
        $filePath = $file->getPathname();
        $isTempCsv = false;

        // Convert Excel to CSV if needed
        if ($originalExtension === 'xlsx') {
            // openpyxl validates the file by its extension, and Laravel's upload
            // tmp path (e.g. /tmp/phpXXXXXX) has none, so give it a proper .xlsx name.
            $tempXlsxPath = tempnam(sys_get_temp_dir(), 'xlsx_migrate_') . '.xlsx';
            copy($filePath, $tempXlsxPath);

            $tempCsvPath = tempnam(sys_get_temp_dir(), 'xlsx_migrate_') . '.csv';
            $pythonScript = base_path('app/Utils/excel_to_csv.py');
            $pythonBin = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
            $command = "{$pythonBin} " . escapeshellarg($pythonScript) . " " . escapeshellarg($tempXlsxPath) . " " . escapeshellarg($tempCsvPath) . " 2>&1";

            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            // If python3 failed on Linux, try python fallback or vice versa
            if ($resultCode !== 0) {
                $fallbackBin = $pythonBin === 'python3' ? 'python' : 'python3';
                $command = "{$fallbackBin} " . escapeshellarg($pythonScript) . " " . escapeshellarg($tempXlsxPath) . " " . escapeshellarg($tempCsvPath) . " 2>&1";
                exec($command, $output, $resultCode);
            }
            unlink($tempXlsxPath);

            if ($resultCode === 0) {
                $filePath = $tempCsvPath;
                $isTempCsv = true;
            } else {
                Log::error('Excel to CSV conversion failed', [
                    'import_id' => $importId,
                    'result_code' => $resultCode,
                    'output' => implode("\n", $output)
                ]);
                return $this->errorResponse($importId, 0, 0, [
                    ['row' => 0, 'field' => 'file', 'value' => null, 'reason' => 'Failed to parse Excel file']
                ], $startTime);
            }
        }

        $handle = fopen($filePath, "r");
        $headers = fgetcsv($handle);

        if (!$headers) {
            fclose($handle);
            if ($isTempCsv && file_exists($filePath)) unlink($filePath);

            return $this->errorResponse($importId, 0, 0, [
                ['row' => 1, 'field' => 'file', 'value' => null, 'reason' => 'File is empty or has no headers']
            ], $startTime);
        }

        $headers = array_map('trim', $headers);

        $chunk = [];
        $chunkSize = 1000;
        $totalCount = 0;
        $allErrors = [];
        $allWarnings = [];
        $importedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $rowNumber = 2;

        while (($row = fgetcsv($handle)) !== false) {
            // Skip empty rows
            if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                $rowNumber++;
                continue;
            }

            // Pad row if needed
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }

            $chunk[] = [
                'rowNumber' => $rowNumber,
                'data' => array_combine($headers, array_slice($row, 0, count($headers)))
            ];
            $totalCount++;

            if (count($chunk) >= $chunkSize) {
                $result = $this->processChunk($section, $chunk);
                $importedCount += $result['imported'];
                $failedCount += $result['failed'];
                $skippedCount += $result['skipped'];
                $allErrors = array_merge($allErrors, $result['errors']);
                $allWarnings = array_merge($allWarnings, $result['warnings']);
                $chunk = [];
            }

            $rowNumber++;
        }

        // Process remaining rows
        if (count($chunk) > 0) {
            $result = $this->processChunk($section, $chunk);
            $importedCount += $result['imported'];
            $failedCount += $result['failed'];
            $skippedCount += $result['skipped'];
            $allErrors = array_merge($allErrors, $result['errors']);
            $allWarnings = array_merge($allWarnings, $result['warnings']);
        }

        fclose($handle);
        if ($isTempCsv && file_exists($filePath)) {
            unlink($filePath);
        }

        $duration = round(microtime(true) - $startTime, 2);
        $success = $failedCount === 0;

        return response()->json([
            'success' => $success,
            'stats' => [
                'total' => $totalCount,
                'imported' => $importedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
                'duration' => $duration
            ],
            'errors' => array_slice($allErrors, 0, 100),
            'warnings' => array_slice($allWarnings, 0, 50),
            'import_id' => $importId
        ], $success ? 200 : 400);
        } catch (\Throwable $e) {
            Log::error('Migration error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage(),
                'error' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    private function errorResponse($importId, $imported, $failed, $errors, $startTime)
    {
        $duration = round(microtime(true) - $startTime, 2);
        return response()->json([
            'success' => false,
            'stats' => [
                'total' => count($errors),
                'imported' => $imported,
                'failed' => $failed,
                'skipped' => 0,
                'duration' => $duration
            ],
            'errors' => $errors,
            'warnings' => [],
            'import_id' => $importId
        ], 400);
    }

    private function processChunk($section, $chunk)
    {
        $now = now();
        $result = [
            'imported' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'warnings' => []
        ];

        if ($section === 'employees') {
            $this->processEmployeeChunk($chunk, $result, $now);
        } elseif ($section === 'patients') {
            $this->processPatientChunk($chunk, $result, $now);
        } elseif ($section === 'appointments') {
            $this->processAppointmentChunk($chunk, $result, $now);
        } elseif ($section === 'clinical') {
            $this->processClinicalChunk($chunk, $result, $now);
        } elseif ($section === 'financial' || $section === 'invoice' || $section === 'invoices') {
            $this->processFinancialChunk($chunk, $result, $now);
        } elseif ($section === 'insurance') {
            $this->processInsuranceChunk($chunk, $result, $now);
        } elseif ($section === 'claim') {
            $this->processClaimChunk($chunk, $result, $now);
        } elseif ($section === 'client' || $section === 'clients') {
            $this->processClientChunk($chunk, $result, $now);
        } elseif ($section === 'lab_order' || $section === 'lab_orders') {
            $this->processLabOrderChunk($chunk, $result, $now);
        } elseif ($section === 'medical_history') {
            $this->processMedicalHistoryChunk($chunk, $result, $now);
        } elseif ($section === 'payroll') {
            $this->processPayrollChunk($chunk, $result, $now);
        } elseif ($section === 'leave_request') {
            $this->processLeaveRequestChunk($chunk, $result, $now);
        } elseif ($section === 'staff_advance') {
            $this->processStaffAdvanceChunk($chunk, $result, $now);
        } elseif ($section === 'insurance_company') {
            $this->processInsuranceCompanyChunk($chunk, $result, $now);
        } elseif ($section === 'insurance_package') {
            $this->processInsurancePackageChunk($chunk, $result, $now);
        } elseif ($section === 'insurance_coverage') {
            $this->processInsuranceCoverageChunk($chunk, $result, $now);
        } elseif ($section === 'insurance_policy') {
            $this->processInsurancePolicyChunk($chunk, $result, $now);
        } elseif ($section === 'odontogram') {
            $this->processOdontogramChunk($chunk, $result, $now);
        } elseif ($section === 'periodontal') {
            $this->processPeriodontalChunk($chunk, $result, $now);
        } elseif ($section === 'orthodontic') {
            $this->processOrthodonticChunk($chunk, $result, $now);
        } elseif ($section === 'endodontic') {
            $this->processEndodonticChunk($chunk, $result, $now);
        } elseif ($section === 'payment') {
            $this->processPaymentChunk($chunk, $result, $now);
        }

        return $result;
    }

    /**
     * Validate a tooth number against Universal Numbering (1-32) or FDI
     * notation (11-18, 21-28, 31-38, 41-48), both used elsewhere in the app.
     * Returns a warning rather than a hard failure since legacy exports vary.
     */
    private function validateTooth($tooth)
    {
        if ($tooth === null || $tooth === '') {
            return ['valid' => true, 'value' => null];
        }

        if (!is_numeric($tooth)) {
            return ['valid' => false, 'value' => null, 'reason' => "Invalid tooth number \"{$tooth}\""];
        }

        $t = (int) $tooth;
        $isUniversal = $t >= 1 && $t <= 32;
        $isFdi = in_array($t, array_merge(range(11, 18), range(21, 28), range(31, 38), range(41, 48)), true);

        if (!$isUniversal && !$isFdi) {
            return ['valid' => false, 'value' => null, 'reason' => "Tooth number {$t} is not a valid Universal (1-32) or FDI notation number"];
        }

        return ['valid' => true, 'value' => $t];
    }

    /**
     * Resolve a treatment catalog id from a procedure name, matching the
     * fallback lookup TreatmentPlan::syncInvoiceForPatientAndDate() uses for
     * live invoices, so migrated invoice_treatments rows stay consistent.
     */
    private function resolveTreatmentIdForProcedure($procedureName)
    {
        if (empty($procedureName)) {
            return null;
        }

        return DB::table('treatments')
            ->whereRaw('LOWER(`procedure`) = ?', [strtolower(trim($procedureName))])
            ->value('id');
    }

    private function normalizePhone($phone)
    {
        return preg_replace('/\D/', '', (string) $phone);
    }

    private function parseDate($date, $default = null)
    {
        if (empty($date)) {
            return $default;
        }

        $dateStr = trim((string) $date);

        // Check if numeric (Excel serial date)
        if (is_numeric($dateStr) && (float) $dateStr > 20000 && (float) $dateStr < 70000) {
            $timestamp = ((float) $dateStr - 25569) * 86400;
            return gmdate('Y-m-d', (int) $timestamp);
        }

        $parsed = strtotime($dateStr);
        if ($parsed === false) {
            // Try DD/MM/YYYY format
            $d = \DateTime::createFromFormat('d/m/Y', $dateStr);
            if ($d !== false) {
                return $d->format('Y-m-d');
            }
            return $default;
        }

        return date('Y-m-d', $parsed);
    }

    private function parseBool($value, $default = false)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    /**
     * Parse a CSV cell expected to hold a JSON object/array (e.g. periodontal
     * pocket depths, canal lists). Returns ['valid' => bool, 'value' => mixed,
     * 'reason' => string|null]; an empty cell resolves to $default without error.
     */
    private function parseJsonField($value, $default = null)
    {
        if ($value === null || $value === '') {
            return ['valid' => true, 'value' => $default, 'reason' => null];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['valid' => false, 'value' => null, 'reason' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        return ['valid' => true, 'value' => $decoded, 'reason' => null];
    }

    /**
     * Batch-resolve the patients referenced by a chunk's 'patient_email' and
     * 'patient_phone' columns, since patients can be identified by either
     * (matching how processPatientChunk accepts email OR phone at creation).
     * Returns [byEmail, byPhone] keyed Collections.
     */
    private function buildPatientLookupMaps($chunk)
    {
        $emails = [];
        $phones = [];

        foreach ($chunk as $item) {
            $pEmail = $this->getRecordValue($item['data'], ['patientemail', 'patient_email', 'email']);
            if ($pEmail) $emails[] = strtolower(trim($pEmail));
            $pPhone = $this->getRecordValue($item['data'], ['patientphone', 'patient_phone', 'phone', 'phone_number', 'phonenumber']);
            if ($pPhone) $phones[] = $this->normalizePhone($pPhone);
        }

        $byEmail = empty($emails)
            ? collect()
            : DB::table('patients')->whereIn('email', $emails)->get()->keyBy(function ($item) {
                return strtolower(trim($item->email));
            });

        $byPhone = empty($phones)
            ? collect()
            : DB::table('patients')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->get()
                ->filter(function ($item) use ($phones) {
                    return in_array($this->normalizePhone($item->phone), $phones, true);
                })
                ->keyBy(function ($item) {
                    return $this->normalizePhone($item->phone);
                });

        return [$byEmail, $byPhone];
    }

    /**
     * Resolve a row's patient using patient_email if present, else
     * patient_phone. Returns ['patient' => object|null, 'identifier' => string|null]
     * - 'identifier' is null only when neither column was provided at all.
     */
    private function findPatientForRow($record, $byEmail, $byPhone)
    {
        $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email', 'email']);
        if (!empty($pEmail)) {
            return [
                'patient' => $byEmail->get(strtolower(trim($pEmail))),
                'identifier' => "email {$pEmail}",
            ];
        }

        $pPhone = $this->getRecordValue($record, ['patientphone', 'patient_phone', 'phone', 'phone_number', 'phonenumber']);
        if (!empty($pPhone)) {
            return [
                'patient' => $byPhone->get($this->normalizePhone($pPhone)),
                'identifier' => "phone {$pPhone}",
            ];
        }

        $pId = $this->getRecordValue($record, ['patientid', 'patient_id']);
        if (!empty($pId) && is_numeric($pId)) {
            $p = DB::table('patients')->where('id', (int)$pId)->first();
            if ($p) {
                return [
                    'patient' => $p,
                    'identifier' => "id {$pId}",
                ];
            }
        }

        $pName = $this->getRecordValue($record, ['patientname', 'patient_name', 'patient']);
        if (!empty($pName)) {
            $matched = DB::table('patients')
                ->whereRaw("TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) LIKE ?", [trim($pName)])
                ->get();
            if ($matched->count() === 1) {
                return [
                    'patient' => $matched->first(),
                    'identifier' => "name {$pName}",
                ];
            }
        }

        return ['patient' => null, 'identifier' => null];
    }

    private function processEmployeeChunk($chunk, &$result, $now)
    {
        $upsertData = [];
        $duplicateEmails = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            // Validate email
            $email = $this->getRecordValue($record, ['email', 'emailaddress', 'email_address']);
            if (empty($email)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'email',
                    'value' => null,
                    'reason' => 'Email is required'
                ];
                $result['failed']++;
                continue;
            }

            $emailValidation = $this->validateEmail($email);
            if (!$emailValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'email',
                    'value' => $email,
                    'reason' => $emailValidation['reason']
                ];
                $result['failed']++;
                continue;
            }

            $emailLower = strtolower(trim($email));

            // Check for duplicates in current batch
            if (in_array($emailLower, $duplicateEmails)) {
                $result['warnings'][] = "Row {$rowNumber}: Duplicate email in batch (skipped)";
                $result['skipped']++;
                continue;
            }
            $duplicateEmails[] = $emailLower;

            // Check if already exists in database
            if (DB::table('employees')->where('email', $emailLower)->exists()) {
                $result['warnings'][] = "Row {$rowNumber}: Email already exists (will update)";
            }

            // Get name
            $name = $this->getRecordValue($record, ['name', 'fullname', 'full_name']);
            if (empty($name)) {
                $firstName = $this->getRecordValue($record, ['firstname', 'first_name']);
                $lastName = $this->getRecordValue($record, ['lastname', 'last_name']);
                $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
            }
            if (empty($name)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'name',
                    'value' => null,
                    'reason' => 'Name is required'
                ];
                $result['failed']++;
                continue;
            }

            // Get role
            $roleId = $this->getRecordValue($record, ['roleid', 'role_id']);
            $roleName = $this->getRecordValue($record, ['role', 'rolename', 'role_name']);

            if (empty($roleId) && !empty($roleName)) {
                $roleId = DB::table('role_permissions')->where('name', $roleName)->value('id') ?? 4;
            } elseif (empty($roleId)) {
                $roleId = 4; // Dentist
            }

            // Get other fields with validation
            $salary = $this->getRecordValue($record, ['salary', 'monthlysalary', 'monthly_salary']) ?? 0;
            if (!is_numeric($salary)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'salary',
                    'value' => $salary,
                    'reason' => 'Salary must be a number'
                ];
                $result['failed']++;
                continue;
            }

            $hireDate = $this->getRecordValue($record, ['hiredate', 'hire_date']) ?? $now->format('Y-m-d');
            $hireValidation = $this->validateDate($hireDate, false);
            if (!$hireValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'hire_date',
                    'value' => $hireDate,
                    'reason' => $hireValidation['reason']
                ];
                $result['failed']++;
                continue;
            }

            $status = $this->getRecordValue($record, ['status']) ?? 'Active';
            if (!in_array($status, ['Active', 'On Leave'])) {
                $status = 'Active';
            }

            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

            $upsertData[] = [
                'name' => $name,
                'email' => $emailLower,
                'role' => $roleName ?? 'Dentist',
                'role_id' => $roleId,
                'salary' => (float)$salary,
                'hire_date' => $hireDate,
                'status' => $status,
                'branch_id' => (int)$branchId,
                'password' => Hash::make('password'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($upsertData)) {
            try {
                DB::table('employees')->upsert(
                    $upsertData,
                    ['email'],
                    ['name', 'role', 'role_id', 'salary', 'hire_date', 'status', 'branch_id', 'updated_at']
                );
            } catch (\Exception $e) {
                Log::error('Employee import error: ' . $e->getMessage());
                $result['failed'] += count($upsertData);
                $result['imported'] -= count($upsertData);
            }
        }
    }

    private function processPatientChunk($chunk, &$result, $now)
    {
        $upsertData = [];
        $phoneOnlyData = [];
        $duplicateEmails = [];
        $duplicatePhones = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            // Email or phone required
            $email = $this->getRecordValue($record, ['email', 'emailaddress', 'email_address']);
            $phone = $this->getRecordValue($record, ['phone', 'phonenumber', 'phone_number']);

            if (empty($email) && empty($phone)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'email/phone',
                    'value' => null,
                    'reason' => 'Email or phone number is required'
                ];
                $result['failed']++;
                continue;
            }

            if (!empty($email)) {
                $emailValidation = $this->validateEmail($email);
                if (!$emailValidation['valid']) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'field' => 'email',
                        'value' => $email,
                        'reason' => $emailValidation['reason']
                    ];
                    $result['failed']++;
                    continue;
                }
                $email = strtolower(trim($email));
            }

            if (!empty($phone)) {
                $phoneValidation = $this->validatePhone($phone);
                if (!$phoneValidation['valid']) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'field' => 'phone',
                        'value' => $phone,
                        'reason' => $phoneValidation['reason']
                    ];
                    $result['failed']++;
                    continue;
                }
            }

            // Check for duplicates - by email when present, else by phone
            if (!empty($email)) {
                if (in_array($email, $duplicateEmails)) {
                    $result['warnings'][] = "Row {$rowNumber}: Duplicate email (skipped)";
                    $result['skipped']++;
                    continue;
                }
                $duplicateEmails[] = $email;
            } elseif (!empty($phone)) {
                $normalizedPhone = $this->normalizePhone($phone);
                if (in_array($normalizedPhone, $duplicatePhones)) {
                    $result['warnings'][] = "Row {$rowNumber}: Duplicate phone (skipped)";
                    $result['skipped']++;
                    continue;
                }
                $duplicatePhones[] = $normalizedPhone;
            }

            // Get name
            $firstName = $this->getRecordValue($record, ['firstname', 'first_name']);
            $lastName = $this->getRecordValue($record, ['lastname', 'last_name']);

            if (empty($firstName)) {
                $fullName = $this->getRecordValue($record, ['name', 'fullname', 'full_name']);
                if (!empty($fullName)) {
                    $parts = explode(' ', trim($fullName), 2);
                    $firstName = $parts[0];
                    $lastName = $parts[1] ?? '';
                }
            }
            if (empty($firstName)) $firstName = 'Unknown';
            if ($lastName === null) $lastName = '';

            // Validate DOB
            $dob = $this->getRecordValue($record, ['dateofbirth', 'date_of_birth', 'birthdate', 'birth_date', 'dob']) ?? '1990-01-01';
            $dobValidation = $this->validateDate($dob, false);
            if (!$dobValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'date_of_birth',
                    'value' => $dob,
                    'reason' => $dobValidation['reason']
                ];
                $result['failed']++;
                continue;
            }

            // Validate gender
            $gender = $this->getRecordValue($record, ['gender']) ?? 'Other';
            if (!empty($gender)) {
                $gender = ucfirst(strtolower(trim($gender)));
                if (!in_array($gender, ['Male', 'Female', 'Other'])) {
                    $gender = 'Other';
                }
            }

            $address = $this->getRecordValue($record, ['address']);
            $status = $this->getRecordValue($record, ['status']) ?? 'Active';
            if (!in_array($status, ['Active', 'Inactive'])) {
                $status = 'Active';
            }
            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;
            $history = $this->getRecordValue($record, ['history', 'medicalhistory', 'medical_history']);

            $row = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                // 'email' has a unique DB index; an empty string (as opposed to
                // NULL) would collide with every other patient missing an email.
                'email' => empty($email) ? null : $email,
                'phone' => $phone,
                'address' => $address,
                'gender' => $gender,
                'date_of_birth' => $dob,
                'status' => $status,
                'branch_id' => (int)$branchId,
                'history' => $history,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (!empty($email)) {
                $upsertData[] = $row;
            } else {
                $phoneOnlyData[] = ['rowNumber' => $rowNumber, 'row' => $row];
            }
            $result['imported']++;
        }

        if (!empty($upsertData)) {
            try {
                DB::table('patients')->upsert(
                    $upsertData,
                    ['email'],
                    ['first_name', 'last_name', 'phone', 'address', 'gender', 'date_of_birth', 'status', 'branch_id', 'history', 'updated_at']
                );
            } catch (\Exception $e) {
                Log::error('Patient import error: ' . $e->getMessage());
                $result['failed'] += count($upsertData);
                $result['imported'] -= count($upsertData);
            }
        }

        // Phone-only rows have no unique DB column to upsert on, so match
        // existing patients by normalized phone in PHP instead.
        if (!empty($phoneOnlyData)) {
            $normalizedPhones = array_map(function ($entry) {
                return $this->normalizePhone($entry['row']['phone']);
            }, $phoneOnlyData);

            $existingByPhone = DB::table('patients')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->get(['id', 'phone'])
                ->filter(function ($item) use ($normalizedPhones) {
                    return in_array($this->normalizePhone($item->phone), $normalizedPhones, true);
                })
                ->keyBy(function ($item) {
                    return $this->normalizePhone($item->phone);
                });

            foreach ($phoneOnlyData as $entry) {
                $row = $entry['row'];
                try {
                    $existing = $existingByPhone->get($this->normalizePhone($row['phone']));
                    if ($existing) {
                        DB::table('patients')->where('id', $existing->id)->update(
                            collect($row)->except('created_at')->toArray()
                        );
                    } else {
                        DB::table('patients')->insert($row);
                    }
                } catch (\Exception $e) {
                    Log::error('Patient import error (phone-only): ' . $e->getMessage());
                    $result['errors'][] = [
                        'row' => $entry['rowNumber'],
                        'field' => 'phone',
                        'value' => $row['phone'],
                        'reason' => 'Failed to save patient record'
                    ];
                    $result['failed']++;
                    $result['imported']--;
                }
            }
        }
    }

    /**
     * Upsert a single tooth's odontogram entry, mirroring the side-effect
     * PatientController@addTreatment already performs when a treatment is
     * logged against a tooth with a condition: update the existing
     * dental_records row for that tooth (preserving its notes if none is
     * given here), or create one. Used by the clinical import's optional
     * 'condition' column.
     */
    private function upsertDentalRecordForTooth($patientId, $toothId, $condition, $notes, $now)
    {
        $existing = DB::table('dental_records')
            ->where('patient_id', $patientId)
            ->where('tooth_id', $toothId)
            ->first();

        if ($existing) {
            DB::table('dental_records')->where('id', $existing->id)->update([
                'condition' => $condition,
                'notes' => ($notes !== null && $notes !== '') ? $notes : $existing->notes,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('dental_records')->insert([
                'patient_id' => $patientId,
                'tooth_id' => $toothId,
                'condition' => $condition,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Odontogram: one dental_records row per tooth per patient. Mirrors
     * PatientController@saveDentalRecord's upsert-by-(patient,tooth) behavior.
     */
    private function processOdontogramChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        $patientIds = [];
        foreach ($chunk as $item) {
            $found = $this->findPatientForRow($item['data'], $patientsByEmail, $patientsByPhone);
            if ($found['patient']) $patientIds[] = $found['patient']->id;
        }

        $existing = empty($patientIds)
            ? collect()
            : DB::table('dental_records')->whereIn('patient_id', array_unique($patientIds))->get()->keyBy(function ($r) {
                return $r->patient_id . ':' . $r->tooth_id;
            });

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
            if (!$found['identifier']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => null,
                    'reason' => 'Patient email or patient phone is required'
                ];
                $result['failed']++;
                continue;
            }

            $p = $found['patient'];
            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            $rawTooth = $this->getRecordValue($record, ['tooth', 'tooth_id', 'tooth_number']);
            $toothValidation = $this->validateTooth($rawTooth);
            if (empty($rawTooth) || !$toothValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'tooth',
                    'value' => $rawTooth,
                    'reason' => empty($rawTooth) ? 'Tooth number is required' : $toothValidation['reason']
                ];
                $result['failed']++;
                continue;
            }
            $tooth = $toothValidation['value'];

            $condition = $this->getRecordValue($record, ['condition']);
            if (empty($condition)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'condition',
                    'value' => null,
                    'reason' => 'Condition is required'
                ];
                $result['failed']++;
                continue;
            }

            $row = [
                'patient_id' => $p->id,
                'tooth_id' => $tooth,
                'condition' => trim($condition),
                'root_condition' => $this->getRecordValue($record, ['root_condition']),
                'notes' => $this->getRecordValue($record, ['notes']),
                'updated_at' => $now,
            ];

            $key = $p->id . ':' . $tooth;
            try {
                $match = $existing->get($key);
                if ($match) {
                    DB::table('dental_records')->where('id', $match->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    $newId = DB::table('dental_records')->insertGetId($row);
                    $existing->put($key, (object) array_merge($row, ['id' => $newId]));
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Odontogram import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'tooth',
                    'value' => $tooth,
                    'reason' => 'Failed to save dental record'
                ];
                $result['failed']++;
            }
        }
    }

    /**
     * Periodontal chart: one periodontal_records row per tooth per patient,
     * upserted by (patient, tooth). Deliberately NOT a destructive
     * replace-all like the live savePeriodontalRecords endpoint, since a
     * migration import may run incrementally across multiple files.
     */
    private function processPeriodontalChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        $patientIds = [];
        foreach ($chunk as $item) {
            $found = $this->findPatientForRow($item['data'], $patientsByEmail, $patientsByPhone);
            if ($found['patient']) $patientIds[] = $found['patient']->id;
        }

        $existing = empty($patientIds)
            ? collect()
            : DB::table('periodontal_records')->whereIn('patient_id', array_unique($patientIds))->get()->keyBy(function ($r) {
                return $r->patient_id . ':' . $r->tooth_id;
            });

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
            if (!$found['identifier']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => null,
                    'reason' => 'Patient email or patient phone is required'
                ];
                $result['failed']++;
                continue;
            }

            $p = $found['patient'];
            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            $rawTooth = $this->getRecordValue($record, ['tooth', 'tooth_id', 'tooth_number']);
            $toothValidation = $this->validateTooth($rawTooth);
            if (empty($rawTooth) || !$toothValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'tooth',
                    'value' => $rawTooth,
                    'reason' => empty($rawTooth) ? 'Tooth number is required' : $toothValidation['reason']
                ];
                $result['failed']++;
                continue;
            }
            $tooth = $toothValidation['value'];

            $pocketsRaw = $this->getRecordValue($record, ['pockets']);
            $pockets = $this->parseJsonField($pocketsRaw);
            if (!$pockets['valid'] || empty($pocketsRaw)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'pockets',
                    'value' => $pocketsRaw,
                    'reason' => empty($pocketsRaw) ? 'Pockets is required, e.g. {"MB":3,"B":2,"DB":3,"ML":2,"L":2,"DL":3}' : $pockets['reason']
                ];
                $result['failed']++;
                continue;
            }

            $recession = $this->getRecordValue($record, ['recession']);
            if (!is_numeric($recession)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'recession',
                    'value' => $recession,
                    'reason' => 'Recession is required and must be a number'
                ];
                $result['failed']++;
                continue;
            }

            $mobility = $this->getRecordValue($record, ['mobility']);
            if (empty($mobility)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'mobility',
                    'value' => null,
                    'reason' => 'Mobility is required'
                ];
                $result['failed']++;
                continue;
            }

            $conditionsRaw = $this->getRecordValue($record, ['periodontal_conditions', 'conditions']);
            $conditions = $this->parseJsonField($conditionsRaw);
            if (!$conditions['valid'] || empty($conditionsRaw)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'periodontal_conditions',
                    'value' => $conditionsRaw,
                    'reason' => empty($conditionsRaw) ? 'Periodontal conditions is required, e.g. ["Gingivitis","Bleeding on probing"]' : $conditions['reason']
                ];
                $result['failed']++;
                continue;
            }

            $mgj = $this->getRecordValue($record, ['mgj']);

            $row = [
                'patient_id' => $p->id,
                'tooth_id' => $tooth,
                'pockets' => json_encode($pockets['value']),
                'recession' => (int) $recession,
                'mgj' => is_numeric($mgj) ? (int) $mgj : null,
                'mobility' => trim($mobility),
                'furcation' => $this->getRecordValue($record, ['furcation']),
                'conditions' => json_encode($conditions['value']),
                'updated_at' => $now,
            ];

            $key = $p->id . ':' . $tooth;
            try {
                $match = $existing->get($key);
                if ($match) {
                    DB::table('periodontal_records')->where('id', $match->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    $newId = DB::table('periodontal_records')->insertGetId($row);
                    $existing->put($key, (object) array_merge($row, ['id' => $newId]));
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Periodontal import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'tooth',
                    'value' => $tooth,
                    'reason' => 'Failed to save periodontal record'
                ];
                $result['failed']++;
            }
        }
    }

    /**
     * Orthodontic chart: append-only history, matching addOrthodonticRecord's
     * live behavior (multiple records per patient/date are valid).
     */
    private function processOrthodonticChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
            if (!$found['identifier']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => null,
                    'reason' => 'Patient email or patient phone is required'
                ];
                $result['failed']++;
                continue;
            }

            $p = $found['patient'];
            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            $appliance = $this->getRecordValue($record, ['appliance']);
            if (empty($appliance)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'appliance',
                    'value' => null,
                    'reason' => 'Appliance is required'
                ];
                $result['failed']++;
                continue;
            }

            $bracketChangesRaw = $this->getRecordValue($record, ['bracket_changes']);
            $bracketChanges = $this->parseJsonField($bracketChangesRaw, []);
            if (!$bracketChanges['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'bracket_changes',
                    'value' => $bracketChangesRaw,
                    'reason' => $bracketChanges['reason']
                ];
                $result['failed']++;
                continue;
            }

            $brokenBracketsRaw = $this->getRecordValue($record, ['broken_brackets']);
            $brokenBrackets = $this->parseJsonField($brokenBracketsRaw, []);
            if (!$brokenBrackets['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'broken_brackets',
                    'value' => $brokenBracketsRaw,
                    'reason' => $brokenBrackets['reason']
                ];
                $result['failed']++;
                continue;
            }

            $rawDate = $this->getRecordValue($record, ['date']);
            $date = $rawDate ? date('Y-m-d', strtotime($rawDate)) : $now->format('Y-m-d');

            $row = [
                'patient_id' => $p->id,
                'date' => $date,
                'appliance' => trim($appliance),
                'appliance_location' => $this->getRecordValue($record, ['appliance_location']),
                'treatment_phase' => $this->getRecordValue($record, ['treatment_phase']),
                'wire_size' => $this->getRecordValue($record, ['wire_size']),
                'wire_size_upper' => $this->getRecordValue($record, ['wire_size_upper']),
                'wire_size_lower' => $this->getRecordValue($record, ['wire_size_lower']),
                'archwire_material' => $this->getRecordValue($record, ['archwire_material']),
                'elastics' => $this->getRecordValue($record, ['elastics']),
                'compliance' => $this->getRecordValue($record, ['compliance']),
                'oral_hygiene' => $this->getRecordValue($record, ['oral_hygiene']),
                'bracket_changes' => json_encode($bracketChanges['value']),
                'broken_brackets' => json_encode($brokenBrackets['value']),
                'notes' => $this->getRecordValue($record, ['notes']),
                'next_visit_goals' => $this->getRecordValue($record, ['next_visit_goals']),
                'updated_at' => $now,
            ];

            // Match key: same patient + date + appliance is treated as the
            // same visit record (updated in place).
            try {
                $existing = DB::table('orthodontic_records')
                    ->where('patient_id', $p->id)
                    ->whereDate('date', $date)
                    ->where('appliance', trim($appliance))
                    ->first();

                if ($existing) {
                    DB::table('orthodontic_records')->where('id', $existing->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    DB::table('orthodontic_records')->insert($row);
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Orthodontic import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'appliance',
                    'value' => $appliance,
                    'reason' => 'Failed to save orthodontic record'
                ];
                $result['failed']++;
            }
        }
    }

    /**
     * Endodontic chart: append-only history, matching EndodonticRecordController
     * ::store's live behavior (always creates, no upsert).
     */
    private function processEndodonticChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
            if (!$found['identifier']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => null,
                    'reason' => 'Patient email or patient phone is required'
                ];
                $result['failed']++;
                continue;
            }

            $p = $found['patient'];
            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            $rawTooth = $this->getRecordValue($record, ['tooth', 'tooth_id', 'tooth_number']);
            $toothValidation = $this->validateTooth($rawTooth);
            if (empty($rawTooth) || !$toothValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'tooth',
                    'value' => $rawTooth,
                    'reason' => empty($rawTooth) ? 'Tooth number is required' : $toothValidation['reason']
                ];
                $result['failed']++;
                continue;
            }
            $tooth = $toothValidation['value'];

            $pulpalDiagnosis = $this->getRecordValue($record, ['pulpal_diagnosis']);
            $periapicalDiagnosis = $this->getRecordValue($record, ['periapical_diagnosis']);
            if (empty($pulpalDiagnosis) || empty($periapicalDiagnosis)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'pulpal_diagnosis/periapical_diagnosis',
                    'value' => null,
                    'reason' => 'Pulpal diagnosis and periapical diagnosis are required'
                ];
                $result['failed']++;
                continue;
            }

            $canalsRaw = $this->getRecordValue($record, ['canals']);
            $canals = $this->parseJsonField($canalsRaw);
            if (!$canals['valid'] || empty($canalsRaw)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'canals',
                    'value' => $canalsRaw,
                    'reason' => empty($canalsRaw) ? 'Canals is required, e.g. ["MB","DB","P"]' : $canals['reason']
                ];
                $result['failed']++;
                continue;
            }

            $rawDate = $this->getRecordValue($record, ['date']);
            if (empty($rawDate)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'date',
                    'value' => null,
                    'reason' => 'Date is required'
                ];
                $result['failed']++;
                continue;
            }
            $dateValidation = $this->validateDate($rawDate, true);
            if (!$dateValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'date',
                    'value' => $rawDate,
                    'reason' => $dateValidation['reason']
                ];
                $result['failed']++;
                continue;
            }

            $date = date('Y-m-d', strtotime($rawDate));
            $row = [
                'patient_id' => $p->id,
                'date' => $date,
                'tooth_id' => $tooth,
                'pulpal_diagnosis' => trim($pulpalDiagnosis),
                'periapical_diagnosis' => trim($periapicalDiagnosis),
                'canals' => json_encode($canals['value']),
                'obturation_material' => $this->getRecordValue($record, ['obturation_material']),
                'post_type' => $this->getRecordValue($record, ['post_type']),
                'notes' => $this->getRecordValue($record, ['notes']),
                'updated_at' => $now,
            ];

            // Match key: same patient + tooth + date is treated as the same
            // treatment session (updated in place).
            try {
                $existing = DB::table('endodontic_records')
                    ->where('patient_id', $p->id)
                    ->where('tooth_id', $tooth)
                    ->whereDate('date', $date)
                    ->first();

                if ($existing) {
                    DB::table('endodontic_records')->where('id', $existing->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    DB::table('endodontic_records')->insert($row);
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Endodontic import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'tooth',
                    'value' => $tooth,
                    'reason' => 'Failed to save endodontic record'
                ];
                $result['failed']++;
            }
        }
    }

    private function processAppointmentChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        $doctorEmails = [];
        foreach ($chunk as $item) {
            $dEmail = $this->getRecordValue($item['data'], ['doctoremail', 'doctor_email']);
            if ($dEmail) $doctorEmails[] = strtolower(trim($dEmail));
        }

        $doctors = DB::table('employees')->whereIn('email', $doctorEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $dEmail = $this->getRecordValue($record, ['doctoremail', 'doctor_email']);

            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
            if (!$found['identifier'] || !$dEmail) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone/doctor_email',
                    'value' => null,
                    'reason' => 'Patient email or phone, and doctor email, are required'
                ];
                $result['failed']++;
                continue;
            }

            $p = $found['patient'];
            $dEmailNorm = strtolower(trim($dEmail));
            $d = $doctors->get($dEmailNorm);
            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            if (!$d) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'doctor_email',
                    'value' => $dEmailNorm,
                    'reason' => "No existing employee found for email {$dEmailNorm}. Import the employee first."
                ];
                $result['failed']++;
                continue;
            }

            $rawDate = $this->getRecordValue($record, ['appointmentdate', 'appointment_date', 'date']);
            $dateTime = $rawDate ? strtotime($rawDate) : false;
            $date = $dateTime ? date('Y-m-d', $dateTime) : $now->format('Y-m-d');

            $rawTime = $this->getRecordValue($record, ['appointmenttime', 'appointment_time', 'time']);
            $timestamp = $rawTime ? strtotime($rawTime) : false;
            $time = $timestamp ? date('H:i', $timestamp) : '10:00';

            $status = $this->getRecordValue($record, ['status']) ?? 'Scheduled';
            if (!in_array($status, ['Scheduled', 'Completed', 'Cancelled', 'Waiting', 'In Session', 'On Hold'])) {
                $status = 'Scheduled';
            }

            $treatment = $this->getRecordValue($record, ['treatment', 'reason', 'procedure']) ?? 'General Checkup';

            $row = [
                'patient_id' => $p->id,
                'patient_name' => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')),
                'doctor_id' => $d->id,
                'doctor_name' => $d->name,
                'date' => $date,
                'time' => $time,
                'treatment' => $treatment,
                'status' => $status,
                'branch_id' => $branchId,
                'updated_at' => $now,
            ];

            // Match key: same patient + doctor + date + time is treated as the
            // same appointment (updated in place); anything else is a new row.
            try {
                $existing = DB::table('appointments')
                    ->where('patient_id', $p->id)
                    ->where('doctor_id', $d->id)
                    ->whereDate('date', $date)
                    ->where('time', $time)
                    ->first();

                if ($existing) {
                    DB::table('appointments')->where('id', $existing->id)->update($row);
                } else {
                    $row['token_number'] = 'TKN-' . rand(1000, 9999);
                    $row['created_at'] = $now;
                    DB::table('appointments')->insert($row);
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Appointment import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'date/time',
                    'value' => "{$date} {$time}",
                    'reason' => 'Failed to save appointment'
                ];
                $result['failed']++;
            }
        }
    }

    private function processClinicalChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
            if (!$found['identifier']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => null,
                    'reason' => 'Patient email or patient phone is required'
                ];
                $result['failed']++;
                continue;
            }

            $p = $found['patient'];
            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            $procedure = $this->getRecordValue($record, ['procedure', 'procedurename', 'procedure_name', 'treatmentname', 'treatment_name', 'treatment']) ?? 'General Checkup';
            $status = $this->getRecordValue($record, ['status']) ?? 'Proposed';
            if (strcasecmp($status, 'Completed') === 0) {
                $status = 'Completed';
            } else {
                $status = 'Proposed';
            }

            $cost = $this->getRecordValue($record, ['cost', 'costamount', 'cost_amount', 'amount']) ?? 0;
            if (!is_numeric($cost)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'cost',
                    'value' => $cost,
                    'reason' => 'Cost must be a number'
                ];
                $result['failed']++;
                continue;
            }

            $rawDate = $this->getRecordValue($record, ['date', 'createdat', 'created_at']);
            $date = $rawDate ? date('Y-m-d', strtotime($rawDate)) : $now->format('Y-m-d');

            $rawTooth = $this->getRecordValue($record, ['tooth', 'tooth_number', 'toothnumber', 'tooth_no', 'toothno', 'teeth']);
            $toothValidation = $this->validateTooth($rawTooth);
            if (!$toothValidation['valid']) {
                $result['warnings'][] = "Row {$rowNumber}: {$toothValidation['reason']} (tooth left blank)";
            }
            $tooth = $toothValidation['value'];

            // Optional: logging a condition against a tooth also updates that
            // tooth's odontogram entry, mirroring PatientController@addTreatment.
            $condition = $this->getRecordValue($record, ['condition']);
            if ($tooth !== null && !empty($condition)) {
                $this->upsertDentalRecordForTooth(
                    $p->id,
                    $tooth,
                    trim($condition),
                    $this->getRecordValue($record, ['notes']),
                    $now
                );
            }

            $row = [
                'patient_id' => $p->id,
                'procedure' => $procedure,
                'tooth' => $tooth,
                'status' => $status,
                'cost' => (float)$cost,
                'date' => $date,
                'updated_at' => $now,
            ];

            $ref = $this->getRecordValue($record, ['ref', 'reference', 'row_ref', 'external_id']);

            // Match key: same patient + procedure + tooth (or no tooth) + date
            // is treated as the same procedure (updated in place).
            try {
                $existingQuery = DB::table('treatment_plans')
                    ->where('patient_id', $p->id)
                    ->where('procedure', $procedure)
                    ->whereDate('date', $date);
                $existingQuery = $tooth === null ? $existingQuery->whereNull('tooth') : $existingQuery->where('tooth', $tooth);
                $existing = $existingQuery->first();

                if ($existing) {
                    DB::table('treatment_plans')->where('id', $existing->id)->update($row);
                    $id = $existing->id;
                } else {
                    $row['created_at'] = $now;
                    $id = DB::table('treatment_plans')->insertGetId($row);
                }

                if (!empty($ref)) {
                    $this->clinicalRefMap[trim($ref)] = [
                        'id' => $id,
                        'procedure' => $procedure,
                        'cost' => (float)$cost,
                        'patient_id' => $p->id,
                    ];
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Clinical import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'procedure',
                    'value' => $procedure,
                    'reason' => 'Failed to save treatment plan'
                ];
                $result['failed']++;
            }
        }
    }

    private function processFinancialChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
            if (!$found['identifier']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => null,
                    'reason' => 'Patient email or patient phone is required'
                ];
                $result['failed']++;
                continue;
            }

            $p = $found['patient'];
            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            $amount = $this->getRecordValue($record, ['amount', 'total', 'total_amount', 'invoice_amount']) ?? 0;
            if (!is_numeric($amount) || (float)$amount < 0) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'amount',
                    'value' => $amount,
                    'reason' => 'Amount must be a positive number'
                ];
                $result['failed']++;
                continue;
            }

            $status = $this->getRecordValue($record, ['status', 'payment_status', 'invoice_status']) ?? 'Pending';
            if (strcasecmp($status, 'Paid') === 0) {
                $status = 'Paid';
            } elseif (strcasecmp($status, 'Overdue') === 0) {
                $status = 'Overdue';
            } else {
                $status = 'Pending';
            }

            $paidAmount = ($status === 'Paid') ? (float)$amount : 0;

            $rawDate = $this->getRecordValue($record, ['date', 'issuedate', 'issue_date', 'invoice_date']);
            $issueDate = $this->parseDate($rawDate, $now->format('Y-m-d'));

            $rawDueDate = $this->getRecordValue($record, ['duedate', 'due_date']);
            $dueDate = $this->parseDate($rawDueDate, date('Y-m-d', strtotime($issueDate . ' +14 days')));

            $paymentMethod = ($status === 'Paid') ? 'Cash' : null;

            // Optional label for the fallback line item when no procedure_refs match.
            $description = $this->getRecordValue($record, ['description', 'procedure', 'treatment', 'item', 'notes']) ?? 'Migrated Treatment';

            // Optional comma/semicolon-separated list of clinical row 'ref' values
            // to attach as real invoice_treatments lines instead of a generic one.
            $refsRaw = $this->getRecordValue($record, ['procedure_refs', 'procedurerefs', 'refs', 'treatment_refs']);

            $patientBalance = max(0, (float)$amount - $paidAmount);

            $invoiceRow = [
                'patient_id' => $p->id,
                'patient_name' => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')),
                'amount' => (float)$amount,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'branch_id' => $branchId,
                'patient_responsibility' => (float)$amount,
                'insurance_responsibility' => 0,
                'updated_at' => $now,
            ];

            $receiptNumber = $this->getRecordValue($record, ['invoice_number', 'invoicenumber', 'receipt_number', 'receiptnumber', 'invoice_no', 'invoiceno', 'ref', 'reference']);
            if (!empty($receiptNumber)) {
                $invoiceRow['receipt_number'] = trim($receiptNumber);
            }

            try {
                // Match key: match by receipt_number if present, else one invoice per patient per branch per day
                $existing = null;
                if (!empty($invoiceRow['receipt_number'])) {
                    $existing = DB::table('invoices')
                        ->where('patient_id', $p->id)
                        ->where('receipt_number', $invoiceRow['receipt_number'])
                        ->first();
                }
                if (!$existing) {
                    $existing = DB::table('invoices')
                        ->where('patient_id', $p->id)
                        ->where('branch_id', $branchId)
                        ->whereDate('issue_date', $issueDate)
                        ->first();
                }

                // If real payments have already been recorded against this invoice
                // (via the 'payment' type, this run or a prior one), they are the
                // authoritative source for paid amounts/status - don't clobber them
                // with the financial row's own status-based guess.
                $hasPayments = $existing && DB::table('payment_transactions')->where('invoice_id', $existing->id)->exists();

                if (!$hasPayments) {
                    $invoiceRow['paid_amount'] = $paidAmount;
                    $invoiceRow['patient_paid_amount'] = $paidAmount;
                    $invoiceRow['insurance_paid_amount'] = 0;
                    $invoiceRow['status'] = $status;
                    $invoiceRow['payment_method'] = $paymentMethod;
                    $invoiceRow['patient_balance'] = $patientBalance;
                    $invoiceRow['insurance_balance'] = 0;
                }

                $invoiceCols = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('invoices'));
                $safeInvoiceRow = array_intersect_key($invoiceRow, $invoiceCols);

                if ($existing) {
                    DB::table('invoices')->where('id', $existing->id)->update($safeInvoiceRow);
                    $invoiceId = $existing->id;
                    // Recreate treatment lines from scratch so re-importing the
                    // same invoice doesn't accumulate duplicate line items.
                    DB::table('invoice_treatments')->where('invoice_id', $invoiceId)->delete();
                } else {
                    $safeInvoiceRow['paid_amount'] = $safeInvoiceRow['paid_amount'] ?? $paidAmount;
                    $safeInvoiceRow['patient_paid_amount'] = $safeInvoiceRow['patient_paid_amount'] ?? $paidAmount;
                    $safeInvoiceRow['insurance_paid_amount'] = $safeInvoiceRow['insurance_paid_amount'] ?? 0;
                    $safeInvoiceRow['status'] = $safeInvoiceRow['status'] ?? $status;
                    $safeInvoiceRow['payment_method'] = $safeInvoiceRow['payment_method'] ?? $paymentMethod;
                    $safeInvoiceRow['patient_balance'] = $safeInvoiceRow['patient_balance'] ?? $patientBalance;
                    $safeInvoiceRow['insurance_balance'] = $safeInvoiceRow['insurance_balance'] ?? 0;
                    $safeInvoiceRow['created_at'] = $now;
                    $invoiceId = DB::table('invoices')->insertGetId($safeInvoiceRow);
                }

                // Optional tag a later 'payment' row can reference via invoice_ref
                // to attach a payment_transactions row to this specific invoice.
                $invoiceRef = $this->getRecordValue($record, ['ref', 'reference', 'row_ref', 'external_id', 'invoice_number', 'invoicenumber', 'receipt_number', 'invoice_no']);
                if (!empty($invoiceRef)) {
                    $this->financialRefMap[trim($invoiceRef)] = [
                        'id' => $invoiceId,
                        'patient_id' => $p->id,
                        'amount' => (float) $amount,
                    ];
                }

                $matchedRefs = [];
                if (!empty($refsRaw)) {
                    foreach (preg_split('/[,;]/', $refsRaw) as $refToken) {
                        $refToken = trim($refToken);
                        if ($refToken === '' || !isset($this->clinicalRefMap[$refToken])) {
                            continue;
                        }
                        $matched = $this->clinicalRefMap[$refToken];
                        if ($matched['patient_id'] === $p->id) {
                            $matchedRefs[] = $matched;
                        }
                    }
                }

                if (!empty($matchedRefs)) {
                    foreach ($matchedRefs as $matched) {
                        DB::table('invoice_treatments')->insert([
                            'invoice_id' => $invoiceId,
                            'procedure' => $matched['procedure'],
                            'procedure_id' => $this->resolveTreatmentIdForProcedure($matched['procedure']),
                            'cost' => $matched['cost'],
                            'quantity' => 1,
                            'unit_price' => $matched['cost'],
                            'total_amount' => $matched['cost'],
                            'insurance_amount' => 0,
                            'patient_amount' => $matched['cost'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                } else {
                    // No matched procedures - add one generic line so the
                    // invoice total still reconciles with a visible treatment.
                    DB::table('invoice_treatments')->insert([
                        'invoice_id' => $invoiceId,
                        'procedure' => $description,
                        'procedure_id' => $this->resolveTreatmentIdForProcedure($description),
                        'cost' => (float)$amount,
                        'quantity' => 1,
                        'unit_price' => (float)$amount,
                        'total_amount' => (float)$amount,
                        'insurance_amount' => 0,
                        'patient_amount' => (float)$amount,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Financial import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'invoice',
                    'value' => null,
                    'reason' => 'Failed to create invoice: ' . $e->getMessage()
                ];
                $result['failed']++;
            }
        }
    }

    /**
     * Payment transaction: attaches a payment_transactions row to an invoice
     * created earlier in the same import (via 'invoice_ref' matching a
     * financial row's 'ref'), and recomputes that invoice's paid_amount /
     * patient_paid_amount / insurance_paid_amount / status / payment_method
     * from ALL of its transactions (not just this chunk's) so multiple
     * payments - and the financial row's own initial paid-amount guess -
     * never double-count.
     */
    private function processPaymentChunk($chunk, &$result, $now)
    {
        $claimNumbers = [];
        foreach ($chunk as $item) {
            $claimNumber = $this->getRecordValue($item['data'], ['claim_number', 'claimnumber']);
            if ($claimNumber) $claimNumbers[] = trim($claimNumber);
        }
        $claims = empty($claimNumbers)
            ? collect()
            : DB::table('insurance_claims')->whereIn('claim_number', $claimNumbers)->get()->keyBy('claim_number');

        $affectedInvoiceIds = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $invoiceRef = $this->getRecordValue($record, ['invoice_ref', 'invoiceref']);
            if (empty($invoiceRef) || !isset($this->financialRefMap[trim($invoiceRef)])) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'invoice_ref',
                    'value' => $invoiceRef,
                    'reason' => empty($invoiceRef)
                        ? 'invoice_ref is required'
                        : "No existing invoice found with ref \"{$invoiceRef}\". Add a 'ref' column to the financial row for this invoice, in this same import."
                ];
                $result['failed']++;
                continue;
            }
            $invoice = $this->financialRefMap[trim($invoiceRef)];

            $amount = $this->getRecordValue($record, ['amount']);
            if (!is_numeric($amount)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'amount',
                    'value' => $amount,
                    'reason' => 'Amount is required and must be a number'
                ];
                $result['failed']++;
                continue;
            }

            $method = $this->getRecordValue($record, ['method', 'payment_method']);
            if (empty($method)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'method',
                    'value' => null,
                    'reason' => 'Method is required'
                ];
                $result['failed']++;
                continue;
            }

            $payerType = strtolower(trim($this->getRecordValue($record, ['payer_type', 'payertype']) ?? 'patient'));
            if (!in_array($payerType, ['patient', 'insurance'])) {
                $payerType = 'patient';
            }

            $insuranceClaimId = null;
            $claimNumber = $this->getRecordValue($record, ['claim_number', 'claimnumber']);
            if (!empty($claimNumber)) {
                $claim = $claims->get(trim($claimNumber));
                if (!$claim) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'field' => 'claim_number',
                        'value' => $claimNumber,
                        'reason' => "No existing claim found with claim_number {$claimNumber}."
                    ];
                    $result['failed']++;
                    continue;
                }
                $insuranceClaimId = $claim->id;
            }

            $rawDate = $this->getRecordValue($record, ['date']);
            $date = $rawDate ? date('Y-m-d', strtotime($rawDate)) : $now->format('Y-m-d');

            try {
                // Payments are ledger entries, not freely mergeable records - two
                // real payments on the same invoice/date for different amounts or
                // methods must both be kept. Only skip when EVERY field matches
                // exactly, treating that as the same row re-imported verbatim
                // rather than update-or-create by a loose key.
                $exists = DB::table('payment_transactions')
                    ->where('invoice_id', $invoice['id'])
                    ->where('insurance_claim_id', $insuranceClaimId)
                    ->where('payer_type', $payerType)
                    ->whereDate('date', $date)
                    ->where('amount', (float) $amount)
                    ->where('method', trim($method))
                    ->exists();

                if (!$exists) {
                    DB::table('payment_transactions')->insert([
                        'invoice_id' => $invoice['id'],
                        'insurance_claim_id' => $insuranceClaimId,
                        'payer_type' => $payerType,
                        'date' => $date,
                        'amount' => (float) $amount,
                        'method' => trim($method),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                $affectedInvoiceIds[$invoice['id']] = true;
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Payment import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'invoice_ref',
                    'value' => $invoiceRef,
                    'reason' => 'Failed to save payment transaction'
                ];
                $result['failed']++;
            }
        }

        foreach (array_keys($affectedInvoiceIds) as $invoiceId) {
            $invoice = DB::table('invoices')->find($invoiceId);
            if (!$invoice) {
                continue;
            }

            $transactions = DB::table('payment_transactions')->where('invoice_id', $invoiceId)->orderBy('id')->get();

            $patientPaid = 0.0;
            $insurancePaid = 0.0;
            $lastMethod = $invoice->payment_method;

            foreach ($transactions as $t) {
                if ($t->payer_type === 'insurance') {
                    $insurancePaid += (float) $t->amount;
                } else {
                    $patientPaid += (float) $t->amount;
                }
                $lastMethod = $t->method;
            }

            $totalPaid = round($patientPaid + $insurancePaid, 2);
            $patientBalance = max(0, (float) $invoice->patient_responsibility - $patientPaid);
            $insuranceBalance = max(0, (float) $invoice->insurance_responsibility - $insurancePaid);

            DB::table('invoices')->where('id', $invoiceId)->update([
                'paid_amount' => $totalPaid,
                'patient_paid_amount' => round($patientPaid, 2),
                'insurance_paid_amount' => round($insurancePaid, 2),
                'patient_balance' => round($patientBalance, 2),
                'insurance_balance' => round($insuranceBalance, 2),
                'status' => $totalPaid >= (float) $invoice->amount ? 'Paid' : 'Pending',
                'payment_method' => $lastMethod,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Insurance Setup chain (processed in this order, each optional -
     * mix and match with pre-existing records identified by code/policy_number):
     * insurance_company -> insurance_package -> insurance_coverage (package's
     * per-procedure coverage rules) -> insurance_policy -> insurance (patient
     * enrollment, below) -> claim.
     */
    private function processInsuranceCompanyChunk($chunk, &$result, $now)
    {
        $upsertData = [];
        $duplicateCodes = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $code = $this->getRecordValue($record, ['code', 'companycode', 'company_code']);
            $name = $this->getRecordValue($record, ['name', 'companyname', 'company_name']);

            if (empty($code) || empty($name)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'code/name',
                    'value' => null,
                    'reason' => 'Company code and name are required'
                ];
                $result['failed']++;
                continue;
            }

            $codeTrim = trim($code);
            if (in_array($codeTrim, $duplicateCodes)) {
                $result['warnings'][] = "Row {$rowNumber}: Duplicate company code (skipped)";
                $result['skipped']++;
                continue;
            }
            $duplicateCodes[] = $codeTrim;

            $email = $this->getRecordValue($record, ['email', 'emailaddress', 'email_address']);
            if (!empty($email)) {
                $emailValidation = $this->validateEmail($email);
                if (!$emailValidation['valid']) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'field' => 'email',
                        'value' => $email,
                        'reason' => $emailValidation['reason']
                    ];
                    $result['failed']++;
                    continue;
                }
            }

            $status = $this->getRecordValue($record, ['status']) ?? 'active';
            if (!in_array(strtolower($status), ['active', 'inactive'])) {
                $status = 'active';
            } else {
                $status = strtolower($status);
            }

            $upsertData[] = [
                'code' => $codeTrim,
                'name' => trim($name),
                'email' => $email,
                'phone' => $this->getRecordValue($record, ['phone', 'phonenumber', 'phone_number']),
                'address' => $this->getRecordValue($record, ['address']),
                'contact_person' => $this->getRecordValue($record, ['contact_person', 'contactperson']),
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($upsertData)) {
            try {
                DB::table('insurance_companies')->upsert(
                    $upsertData,
                    ['code'],
                    ['name', 'email', 'phone', 'address', 'contact_person', 'status', 'updated_at']
                );
            } catch (\Exception $e) {
                Log::error('Insurance company import error: ' . $e->getMessage());
                $result['failed'] += count($upsertData);
                $result['imported'] -= count($upsertData);
            }
        }
    }

    private function processInsurancePackageChunk($chunk, &$result, $now)
    {
        $companyCodes = [];
        foreach ($chunk as $item) {
            $companyCode = $this->getRecordValue($item['data'], ['company_code', 'companycode']);
            if ($companyCode) $companyCodes[] = trim($companyCode);
        }

        $companies = DB::table('insurance_companies')->whereIn('code', $companyCodes)->get()->keyBy('code');

        $upsertData = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $companyCode = $this->getRecordValue($record, ['company_code', 'companycode']);
            $code = $this->getRecordValue($record, ['code', 'packagecode', 'package_code']);
            $name = $this->getRecordValue($record, ['name', 'packagename', 'package_name']);

            if (empty($companyCode) || empty($code) || empty($name)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'company_code/code/name',
                    'value' => null,
                    'reason' => 'Company code, package code, and name are required'
                ];
                $result['failed']++;
                continue;
            }

            $company = $companies->get(trim($companyCode));
            if (!$company) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'company_code',
                    'value' => $companyCode,
                    'reason' => "No existing insurance company found with code {$companyCode}. Import the insurance_company row first."
                ];
                $result['failed']++;
                continue;
            }

            $coveragePct = $this->getRecordValue($record, ['default_coverage_percentage', 'defaultcoveragepercentage']);
            if ($coveragePct !== null && (!is_numeric($coveragePct) || $coveragePct < 0 || $coveragePct > 100)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'default_coverage_percentage',
                    'value' => $coveragePct,
                    'reason' => 'Default coverage percentage must be between 0 and 100'
                ];
                $result['failed']++;
                continue;
            }

            $annualLimit = $this->getRecordValue($record, ['default_annual_limit', 'defaultannuallimit']);
            if ($annualLimit !== null && (!is_numeric($annualLimit) || $annualLimit < 0)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'default_annual_limit',
                    'value' => $annualLimit,
                    'reason' => 'Default annual limit must be a non-negative number'
                ];
                $result['failed']++;
                continue;
            }

            $status = $this->getRecordValue($record, ['status']) ?? 'active';
            if (!in_array(strtolower($status), ['active', 'inactive'])) {
                $status = 'active';
            } else {
                $status = strtolower($status);
            }

            $upsertData[] = [
                'insurance_company_id' => $company->id,
                'code' => trim($code),
                'name' => trim($name),
                'description' => $this->getRecordValue($record, ['description']),
                'default_coverage_percentage' => $coveragePct !== null ? (float)$coveragePct : 100.00,
                'default_annual_limit' => $annualLimit !== null ? (float)$annualLimit : null,
                'requires_pre_authorization' => $this->parseBool($this->getRecordValue($record, ['requires_pre_authorization', 'requirespreauthorization'])),
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($upsertData)) {
            try {
                DB::table('insurance_packages')->upsert(
                    $upsertData,
                    ['insurance_company_id', 'code'],
                    ['name', 'description', 'default_coverage_percentage', 'default_annual_limit', 'requires_pre_authorization', 'status', 'updated_at']
                );
            } catch (\Exception $e) {
                Log::error('Insurance package import error: ' . $e->getMessage());
                $result['failed'] += count($upsertData);
                $result['imported'] -= count($upsertData);
            }
        }
    }

    private function processInsuranceCoverageChunk($chunk, &$result, $now)
    {
        $companyCodes = [];
        $packageCodes = [];
        $procedureNames = [];
        foreach ($chunk as $item) {
            $companyCode = $this->getRecordValue($item['data'], ['company_code', 'companycode']);
            if ($companyCode) $companyCodes[] = trim($companyCode);
            $packageCode = $this->getRecordValue($item['data'], ['package_code', 'packagecode']);
            if ($packageCode) $packageCodes[] = trim($packageCode);
            $procedure = $this->getRecordValue($item['data'], ['procedure', 'procedurename', 'procedure_name']);
            if ($procedure) $procedureNames[] = trim($procedure);
        }

        $companies = DB::table('insurance_companies')->whereIn('code', $companyCodes)->get()->keyBy('code');
        $packages = empty($packageCodes)
            ? collect()
            : DB::table('insurance_packages')->whereIn('code', $packageCodes)->get()->groupBy('code');
        $treatments = empty($procedureNames)
            ? collect()
            : DB::table('treatments')->get()->filter(function ($t) use ($procedureNames) {
                foreach ($procedureNames as $p) {
                    if (strcasecmp(trim($t->procedure), $p) === 0) return true;
                }
                return false;
            })->keyBy(function ($t) {
                return strtolower(trim($t->procedure));
            });

        $upsertData = [];
        $validCoverageTypes = ['percentage', 'fixed_amount'];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $companyCode = $this->getRecordValue($record, ['company_code', 'companycode']);
            $packageCode = $this->getRecordValue($record, ['package_code', 'packagecode']);
            $procedureName = $this->getRecordValue($record, ['procedure', 'procedurename', 'procedure_name']);

            if (empty($companyCode) || empty($packageCode) || empty($procedureName)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'company_code/package_code/procedure',
                    'value' => null,
                    'reason' => 'Company code, package code, and procedure are required'
                ];
                $result['failed']++;
                continue;
            }

            $company = $companies->get(trim($companyCode));
            if (!$company) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'company_code',
                    'value' => $companyCode,
                    'reason' => "No existing insurance company found with code {$companyCode}. Import the insurance_company row first."
                ];
                $result['failed']++;
                continue;
            }

            $package = ($packages->get(trim($packageCode)) ?? collect())
                ->firstWhere('insurance_company_id', $company->id);
            if (!$package) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'package_code',
                    'value' => $packageCode,
                    'reason' => "No existing insurance package found with code {$packageCode} for company {$companyCode}. Import the insurance_package row first."
                ];
                $result['failed']++;
                continue;
            }

            $treatment = $treatments->get(strtolower(trim($procedureName)));
            if (!$treatment) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'procedure',
                    'value' => $procedureName,
                    'reason' => "No existing treatment found named \"{$procedureName}\" in the treatment catalog."
                ];
                $result['failed']++;
                continue;
            }

            $coverageType = strtolower(trim($this->getRecordValue($record, ['coverage_type', 'coveragetype']) ?? 'percentage'));
            if (!in_array($coverageType, $validCoverageTypes)) {
                $coverageType = 'percentage';
            }

            $coverageValue = $this->getRecordValue($record, ['coverage_value', 'coveragevalue']) ?? 100;
            if (!is_numeric($coverageValue) || $coverageValue < 0) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'coverage_value',
                    'value' => $coverageValue,
                    'reason' => 'Coverage value must be a non-negative number'
                ];
                $result['failed']++;
                continue;
            }

            $maxCovered = $this->getRecordValue($record, ['maximum_covered_amount', 'maximumcoveredamount']);
            $frequencyLimit = $this->getRecordValue($record, ['frequency_limit', 'frequencylimit']);
            $waitingPeriod = $this->getRecordValue($record, ['waiting_period_days', 'waitingperioddays']) ?? 0;

            $status = $this->getRecordValue($record, ['status']) ?? 'active';
            if (!in_array(strtolower($status), ['active', 'inactive'])) {
                $status = 'active';
            } else {
                $status = strtolower($status);
            }

            $upsertData[] = [
                'insurance_package_id' => $package->id,
                'procedure_id' => $treatment->id,
                'coverage_type' => $coverageType,
                'coverage_value' => (float)$coverageValue,
                'maximum_covered_amount' => is_numeric($maxCovered) ? (float)$maxCovered : null,
                'frequency_limit' => is_numeric($frequencyLimit) ? (int)$frequencyLimit : null,
                'waiting_period_days' => is_numeric($waitingPeriod) ? (int)$waitingPeriod : 0,
                'requires_pre_authorization' => $this->parseBool($this->getRecordValue($record, ['requires_pre_authorization', 'requirespreauthorization'])),
                'exclusion_notes' => $this->getRecordValue($record, ['exclusion_notes', 'notes']),
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($upsertData)) {
            try {
                DB::table('insurance_package_treatments')->upsert(
                    $upsertData,
                    ['insurance_package_id', 'procedure_id'],
                    ['coverage_type', 'coverage_value', 'maximum_covered_amount', 'frequency_limit', 'waiting_period_days', 'requires_pre_authorization', 'exclusion_notes', 'status', 'updated_at']
                );
            } catch (\Exception $e) {
                Log::error('Insurance coverage import error: ' . $e->getMessage());
                $result['failed'] += count($upsertData);
                $result['imported'] -= count($upsertData);
            }
        }
    }

    private function processInsurancePolicyChunk($chunk, &$result, $now)
    {
        $companyCodes = [];
        $packageCodes = [];
        foreach ($chunk as $item) {
            $companyCode = $this->getRecordValue($item['data'], ['company_code', 'companycode']);
            if ($companyCode) $companyCodes[] = trim($companyCode);
            $packageCode = $this->getRecordValue($item['data'], ['package_code', 'packagecode']);
            if ($packageCode) $packageCodes[] = trim($packageCode);
        }

        $companies = DB::table('insurance_companies')->whereIn('code', $companyCodes)->get()->keyBy('code');
        $packages = empty($packageCodes)
            ? collect()
            : DB::table('insurance_packages')->whereIn('code', $packageCodes)->get()->groupBy('code');

        $validPolicyTypes = ['individual', 'family', 'corporate'];
        $validStatuses = ['pending', 'active', 'suspended', 'expired', 'cancelled'];

        $upsertData = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $companyCode = $this->getRecordValue($record, ['company_code', 'companycode']);
            $packageCode = $this->getRecordValue($record, ['package_code', 'packagecode']);
            $policyNumber = $this->getRecordValue($record, ['policynumber', 'policy_number']);

            if (empty($companyCode) || empty($packageCode) || empty($policyNumber)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'company_code/package_code/policy_number',
                    'value' => null,
                    'reason' => 'Company code, package code, and policy number are required'
                ];
                $result['failed']++;
                continue;
            }

            $company = $companies->get(trim($companyCode));
            if (!$company) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'company_code',
                    'value' => $companyCode,
                    'reason' => "No existing insurance company found with code {$companyCode}. Import the insurance_company row first."
                ];
                $result['failed']++;
                continue;
            }

            $package = ($packages->get(trim($packageCode)) ?? collect())
                ->firstWhere('insurance_company_id', $company->id);
            if (!$package) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'package_code',
                    'value' => $packageCode,
                    'reason' => "No existing insurance package found with code {$packageCode} for company {$companyCode}. Import the insurance_package row first."
                ];
                $result['failed']++;
                continue;
            }

            $startDate = $this->getRecordValue($record, ['coverage_start_date', 'coveragestartdate']);
            $startValidation = $this->validateDate($startDate, true);
            if (!$startValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'coverage_start_date',
                    'value' => $startDate,
                    'reason' => $startValidation['reason']
                ];
                $result['failed']++;
                continue;
            }

            $endDate = $this->getRecordValue($record, ['coverage_end_date', 'coverageenddate']);
            $endValidation = $this->validateDate($endDate, true);
            if (!$endValidation['valid']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'coverage_end_date',
                    'value' => $endDate,
                    'reason' => $endValidation['reason']
                ];
                $result['failed']++;
                continue;
            }

            if (strtotime($endDate) <= strtotime($startDate)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'coverage_end_date',
                    'value' => $endDate,
                    'reason' => 'Coverage end date must be after the coverage start date'
                ];
                $result['failed']++;
                continue;
            }

            $policyType = strtolower(trim($this->getRecordValue($record, ['policy_type', 'policytype']) ?? 'individual'));
            if (!in_array($policyType, $validPolicyTypes)) {
                $policyType = 'individual';
            }

            $totalLimit = $this->getRecordValue($record, ['total_coverage_limit', 'totalcoveragelimit']);
            if ($totalLimit !== null && (!is_numeric($totalLimit) || $totalLimit < 0)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'total_coverage_limit',
                    'value' => $totalLimit,
                    'reason' => 'Total coverage limit must be a non-negative number'
                ];
                $result['failed']++;
                continue;
            }

            $status = strtolower(trim($this->getRecordValue($record, ['status']) ?? 'pending'));
            if (!in_array($status, $validStatuses)) {
                $status = 'pending';
            }

            $upsertData[] = [
                'insurance_package_id' => $package->id,
                'policy_number' => trim($policyNumber),
                'policy_type' => $policyType,
                'employer_or_group_name' => $this->getRecordValue($record, ['employer_or_group_name', 'employerorgroupname']),
                'coverage_start_date' => date('Y-m-d', strtotime($startDate)),
                'coverage_end_date' => date('Y-m-d', strtotime($endDate)),
                'total_coverage_limit' => $totalLimit !== null ? (float)$totalLimit : null,
                'used_amount' => 0,
                'remaining_balance' => $totalLimit !== null ? (float)$totalLimit : null,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($upsertData)) {
            try {
                DB::table('insurance_policies')->upsert(
                    $upsertData,
                    ['policy_number'],
                    ['insurance_package_id', 'policy_type', 'employer_or_group_name', 'coverage_start_date', 'coverage_end_date', 'total_coverage_limit', 'remaining_balance', 'status', 'updated_at']
                );
            } catch (\Exception $e) {
                Log::error('Insurance policy import error: ' . $e->getMessage());
                $result['failed'] += count($upsertData);
                $result['imported'] -= count($upsertData);
            }
        }
    }

    /**
     * Attach a patient to an existing insurance policy (patient_insurance_policies).
     * The policy can come from a pre-existing setup, or from insurance_company /
     * insurance_package / insurance_policy rows earlier in the same import.
     */
    private function processInsuranceChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        $policyNumbers = [];
        foreach ($chunk as $item) {
            $policyNo = $this->getRecordValue($item['data'], ['policynumber', 'policy_number']);
            if ($policyNo) $policyNumbers[] = trim($policyNo);
        }

        $policies = DB::table('insurance_policies')->whereIn('policy_number', $policyNumbers)->get()->keyBy('policy_number');

        $insertData = [];
        $validRelationships = ['self', 'spouse', 'child', 'other'];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $policyNo = $this->getRecordValue($record, ['policynumber', 'policy_number']);
            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);

            if (!$found['identifier'] || !$policyNo) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone/policy_number',
                    'value' => null,
                    'reason' => 'Patient email or phone, and policy number, are required'
                ];
                $result['failed']++;
                continue;
            }

            $policyNoTrim = trim($policyNo);
            $p = $found['patient'];
            $policy = $policies->get($policyNoTrim);

            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            if (!$policy) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'policy_number',
                    'value' => $policyNoTrim,
                    'reason' => "No insurance policy found with policy_number {$policyNoTrim}. Set up the insurance policy first."
                ];
                $result['failed']++;
                continue;
            }

            $subscriberId = $this->getRecordValue($record, ['subscriberid', 'subscriber_id']);
            if (empty($subscriberId)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'subscriber_id',
                    'value' => null,
                    'reason' => 'Subscriber ID is required'
                ];
                $result['failed']++;
                continue;
            }

            $relationship = strtolower(trim($this->getRecordValue($record, ['relationshiptosubscriber', 'relationship_to_subscriber']) ?? 'self'));
            if (!in_array($relationship, $validRelationships)) {
                $relationship = 'self';
            }

            $groupNumber = $this->getRecordValue($record, ['groupnumber', 'group_number']);
            $subscriberName = $this->getRecordValue($record, ['subscribername', 'subscriber_name']);
            $isPrimaryRaw = strtolower(trim($this->getRecordValue($record, ['isprimary', 'is_primary']) ?? ''));
            $isPrimary = in_array($isPrimaryRaw, ['1', 'true', 'yes']);

            $insertData[] = [
                'patient_id' => $p->id,
                'insurance_policy_id' => $policy->id,
                'subscriber_id' => $subscriberId,
                'group_number' => $groupNumber,
                'relationship_to_subscriber' => $relationship,
                'subscriber_name' => $subscriberName,
                'is_primary' => $isPrimary,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($insertData)) {
            try {
                foreach ($insertData as $row) {
                    if ($row['is_primary']) {
                        DB::table('patient_insurance_policies')->where('patient_id', $row['patient_id'])->update(['is_primary' => false]);
                    }
                    DB::table('patient_insurance_policies')->updateOrInsert(
                        ['patient_id' => $row['patient_id'], 'insurance_policy_id' => $row['insurance_policy_id']],
                        $row
                    );
                }
            } catch (\Exception $e) {
                Log::error('Insurance import error: ' . $e->getMessage());
                $result['failed'] += count($insertData);
                $result['imported'] -= count($insertData);
            }
        }
    }

    /**
     * Create an insurance claim against a patient's existing policy enrollment
     * (patient_insurance_policies). The patient must already be enrolled in the
     * policy - via an 'insurance' row earlier in the same file, or beforehand.
     */
    private function processClaimChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        $policyNumbers = [];
        foreach ($chunk as $item) {
            $policyNo = $this->getRecordValue($item['data'], ['policynumber', 'policy_number']);
            if ($policyNo) $policyNumbers[] = trim($policyNo);
        }

        $policies = DB::table('insurance_policies')->whereIn('policy_number', $policyNumbers)
            ->get()->keyBy('policy_number');

        $validStatuses = ['Draft', 'Submitted', 'Under Review', 'Approved', 'Partially Approved', 'Rejected', 'Paid', 'Cancelled'];
        $insertData = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $policyNo = $this->getRecordValue($record, ['policynumber', 'policy_number']);
            $claimNumber = $this->getRecordValue($record, ['claimnumber', 'claim_number']);
            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);

            if (!$found['identifier'] || !$policyNo || empty($claimNumber)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone/policy_number/claim_number',
                    'value' => null,
                    'reason' => 'Patient email or phone, policy number, and claim number are required'
                ];
                $result['failed']++;
                continue;
            }

            $policyNoTrim = trim($policyNo);
            $p = $found['patient'];
            $policy = $policies->get($policyNoTrim);

            if (!$p) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            if (!$policy) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'policy_number',
                    'value' => $policyNoTrim,
                    'reason' => "No insurance policy found with policy_number {$policyNoTrim}. Set up the insurance policy first."
                ];
                $result['failed']++;
                continue;
            }

            $enrollment = DB::table('patient_insurance_policies')
                ->where('patient_id', $p->id)
                ->where('insurance_policy_id', $policy->id)
                ->first();

            if (!$enrollment) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'policy_number',
                    'value' => $policyNoTrim,
                    'reason' => "Patient {$pEmailNorm} is not enrolled in policy {$policyNoTrim}. Add an 'insurance' row to enroll them first."
                ];
                $result['failed']++;
                continue;
            }

            if (DB::table('insurance_claims')->where('claim_number', trim($claimNumber))->exists()) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'claim_number',
                    'value' => $claimNumber,
                    'reason' => "Claim number {$claimNumber} already exists"
                ];
                $result['failed']++;
                continue;
            }

            $claimedAmount = $this->getRecordValue($record, ['claimedamount', 'claimed_amount', 'amount']);
            if (!is_numeric($claimedAmount)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'claimed_amount',
                    'value' => $claimedAmount,
                    'reason' => 'Claimed amount must be a number'
                ];
                $result['failed']++;
                continue;
            }

            $approvedAmount = $this->getRecordValue($record, ['approvedamount', 'approved_amount']);

            $status = $this->getRecordValue($record, ['status']) ?? 'Submitted';
            $matchedStatus = null;
            foreach ($validStatuses as $validStatus) {
                if (strcasecmp($status, $validStatus) === 0) {
                    $matchedStatus = $validStatus;
                    break;
                }
            }
            $status = $matchedStatus ?? 'Submitted';

            $rawSubmitted = $this->getRecordValue($record, ['datesubmitted', 'date_submitted']);
            $submissionDate = $rawSubmitted ? date('Y-m-d', strtotime($rawSubmitted)) : $now->format('Y-m-d');

            $rawProcessed = $this->getRecordValue($record, ['dateprocessed', 'date_processed']);
            $approvalDate = $rawProcessed ? date('Y-m-d', strtotime($rawProcessed)) : null;

            $notes = $this->getRecordValue($record, ['notes']);

            $insertData[] = [
                'claim_number' => trim($claimNumber),
                'patient_id' => $p->id,
                'insurance_policy_id' => $policy->id,
                'insurance_company_id' => DB::table('insurance_packages')->where('id', $policy->insurance_package_id)->value('insurance_company_id'),
                'submitted_amount' => (float)$claimedAmount,
                'approved_amount' => is_numeric($approvedAmount) ? (float)$approvedAmount : 0,
                'submission_date' => $submissionDate,
                'approval_date' => $approvalDate,
                'status' => $status,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($insertData)) {
            try {
                DB::table('insurance_claims')->insert($insertData);
            } catch (\Exception $e) {
                Log::error('Claim import error: ' . $e->getMessage());
                $result['failed'] += count($insertData);
                $result['imported'] -= count($insertData);
            }
        }
    }

    /**
     * Import lab/company clients - the outsourced laboratory a LaboratoryOrder
     * is sent to (not a patient or referring dentist). There is no unique DB
     * constraint on email, so existing rows are matched by name instead.
     */
    private function processClientChunk($chunk, &$result, $now)
    {
        $names = [];
        foreach ($chunk as $item) {
            $name = $this->getRecordValue($item['data'], ['name', 'clientname', 'client_name']);
            if ($name) $names[] = trim($name);
        }

        $existing = DB::table('clients')->whereIn('name', $names)->get()->keyBy(function ($item) {
            return strtolower(trim($item->name));
        });

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $name = $this->getRecordValue($record, ['name', 'clientname', 'client_name']);
            if (empty($name)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'name',
                    'value' => null,
                    'reason' => 'Client name is required'
                ];
                $result['failed']++;
                continue;
            }

            $row = [
                'name' => trim($name),
                'email' => $this->getRecordValue($record, ['email', 'emailaddress', 'email_address']),
                'phone' => $this->getRecordValue($record, ['phone', 'phonenumber', 'phone_number']),
                'address' => $this->getRecordValue($record, ['address']),
                'updated_at' => $now,
            ];

            try {
                $match = $existing->get(strtolower(trim($name)));
                if ($match) {
                    DB::table('clients')->where('id', $match->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    DB::table('clients')->insertGetId($row);
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Client import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'name',
                    'value' => $name,
                    'reason' => 'Failed to save client'
                ];
                $result['failed']++;
            }
        }
    }

    /**
     * Import lab orders, resolving client (by name) and doctor (by email)
     * references. Both client_id and doctor_id are optional/nullable.
     */
    /**
     * Import lab orders, resolving client (by name/lab) and doctor (by name/email).
     * Automatically auto-creates client if not found so imports are never blocked.
     * Patient info (name, email, phone) is preserved in instructions as metadata.
     */
    private function processLabOrderChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        $doctorEmails = [];
        $clientNames = [];
        foreach ($chunk as $item) {
            $dEmail = $this->getRecordValue($item['data'], ['doctoremail', 'doctor_email']);
            if ($dEmail) $doctorEmails[] = strtolower(trim($dEmail));
            $clientName = $this->getRecordValue($item['data'], ['clientname', 'client_name', 'client', 'labname', 'lab_name', 'lab', 'dental_lab', 'dentallab']);
            if ($clientName) $clientNames[] = trim($clientName);
        }

        $doctorsByEmail = empty($doctorEmails)
            ? collect()
            : DB::table('employees')->whereIn('email', $doctorEmails)->get()->keyBy(function ($item) {
                return strtolower(trim($item->email));
            });

        $clients = empty($clientNames)
            ? collect()
            : DB::table('clients')->get()->keyBy(function ($item) {
                return strtolower(trim($item->name));
            });

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            try {
                // 1. Client / Dental Lab Resolution
                $clientName = $this->getRecordValue($record, ['clientname', 'client_name', 'client', 'labname', 'lab_name', 'lab', 'dental_lab', 'dentallab']);
                $clientId = null;
                if (!empty($clientName)) {
                    $normClientName = strtolower(trim($clientName));
                    $client = $clients->get($normClientName);
                    if ($client) {
                        $clientId = $client->id;
                    } else {
                        // Check DB directly in case added during this import batch
                        $dbClient = DB::table('clients')->whereRaw('LOWER(name) = ?', [$normClientName])->first();
                        if ($dbClient) {
                            $clientId = $dbClient->id;
                            $clients->put($normClientName, $dbClient);
                        } else {
                            // Auto-create client (dental lab)
                            $clientId = DB::table('clients')->insertGetId([
                                'name' => trim($clientName),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $newClientObj = (object)['id' => $clientId, 'name' => trim($clientName)];
                            $clients->put($normClientName, $newClientObj);
                        }
                    }
                }

                // 2. Item / Procedure / Service
                $itemName = $this->getRecordValue($record, ['item', 'itemname', 'item_name', 'service', 'servicename', 'service_name', 'procedure']);
                if (empty($itemName)) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'field' => 'item',
                        'value' => null,
                        'reason' => 'Item or service name is required'
                    ];
                    $result['failed']++;
                    continue;
                }

                // 3. Doctor Name & Employee ID
                $doctorName = $this->getRecordValue($record, ['doctorname', 'doctor_name', 'doctor', 'attending_doctor', 'clinician']);
                $employeeId = null;
                $dEmail = $this->getRecordValue($record, ['doctoremail', 'doctor_email']);
                if (!empty($dEmail)) {
                    $doc = $doctorsByEmail->get(strtolower(trim($dEmail)));
                    if ($doc) {
                        $employeeId = $doc->id;
                        if (empty($doctorName)) {
                            $doctorName = trim(($doc->first_name ?? '') . ' ' . ($doc->last_name ?? ''));
                            if (empty($doctorName) && !empty($doc->name)) {
                                $doctorName = $doc->name;
                            }
                        }
                    }
                }
                if (empty($doctorName)) {
                    $doctorName = 'Attending Doctor';
                }

                // 4. Patient Information (metadata preserved in instructions)
                $patientName = $this->getRecordValue($record, ['patientname', 'patient_name']);
                $patientEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
                $patientPhone = $this->getRecordValue($record, ['patientphone', 'patient_phone']);

                $patientFound = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
                if ($patientFound['patient']) {
                    $resolvedPName = trim(($patientFound['patient']->first_name ?? '') . ' ' . ($patientFound['patient']->last_name ?? ''));
                    if (empty($patientName) && !empty($resolvedPName)) {
                        $patientName = $resolvedPName;
                    }
                }

                $instructions = $this->getRecordValue($record, ['instructions', 'instruction', 'notes', 'note']);
                $patientNote = '';
                if (!empty($patientName)) {
                    $contact = !empty($patientEmail) ? $patientEmail : (!empty($patientPhone) ? $patientPhone : '');
                    $patientNote = "Patient: {$patientName}" . (!empty($contact) ? " ({$contact})" : "");
                } elseif (!empty($patientEmail)) {
                    $patientNote = "Patient: {$patientEmail}";
                }

                // 5. Tooth Validation
                $rawTooth = $this->getRecordValue($record, ['tooth', 'tooth_number', 'toothnumber', 'tooth_no', 'toothno']);
                $tooth = null;
                if (!empty($rawTooth)) {
                    $toothValidation = $this->validateTooth($rawTooth);
                    if ($toothValidation['valid']) {
                        $tooth = $toothValidation['value'];
                    } else {
                        // Extract first digit if range like '14-16'
                        if (preg_match('/^(\d+)/', trim((string)$rawTooth), $m)) {
                            $firstTooth = (int)$m[1];
                            $firstVal = $this->validateTooth($firstTooth);
                            if ($firstVal['valid']) {
                                $tooth = $firstVal['value'];
                            }
                        }
                        $rangeNote = "Teeth: {$rawTooth}";
                        $instructions = empty($instructions) ? $rangeNote : ($instructions . " | " . $rangeNote);
                        $result['warnings'][] = "Row {$rowNumber}: {$toothValidation['reason']} (stored tooth as " . ($tooth ?? 'blank') . ")";
                    }
                }

                if (!empty($patientNote)) {
                    if (empty($instructions)) {
                        $instructions = $patientNote;
                    } elseif (stripos($instructions, $patientName ?? $patientNote) === false) {
                        $instructions = $patientNote . " | " . $instructions;
                    }
                }

                // 6. Status Mapping to valid DB ENUM ('Draft', 'Received', 'In Production', 'Ready for Delivery', 'Delivered', 'Cancelled')
                $rawStatus = strtolower(trim((string)($this->getRecordValue($record, ['status']) ?? '')));
                $status = 'Received';
                if (in_array($rawStatus, ['draft'])) {
                    $status = 'Draft';
                } elseif (in_array($rawStatus, ['in progress', 'in_progress', 'in production', 'in_production', 'production', 'processing'])) {
                    $status = 'In Production';
                } elseif (in_array($rawStatus, ['ready', 'ready for delivery', 'ready_for_delivery'])) {
                    $status = 'Ready for Delivery';
                } elseif (in_array($rawStatus, ['completed', 'delivered', 'done'])) {
                    $status = 'Delivered';
                } elseif (in_array($rawStatus, ['cancelled', 'canceled'])) {
                    $status = 'Cancelled';
                } elseif (in_array($rawStatus, ['sent', 'received', 'pending'])) {
                    $status = 'Received';
                }

                // 7. Dates
                $rawSentDate = $this->getRecordValue($record, ['sentdate', 'sent_date', 'date']);
                $sentDate = $this->parseDate($rawSentDate, $now->format('Y-m-d'));

                $rawDueDate = $this->getRecordValue($record, ['duedate', 'due_date']);
                $dueDate = $this->parseDate($rawDueDate, date('Y-m-d', strtotime($sentDate . ' +7 days')));

                $rawReceivedDate = $this->getRecordValue($record, ['receiveddate', 'received_date']);
                $receivedDate = $this->parseDate($rawReceivedDate, null);

                // 8. Financial & Other fields
                $rawPrice = $this->getRecordValue($record, ['appliedprice', 'applied_price', 'price', 'cost', 'amount']);
                $appliedPrice = is_numeric($rawPrice) ? (float)$rawPrice : null;

                $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;
                $shade = $this->getRecordValue($record, ['shade']);
                $material = $this->getRecordValue($record, ['material']);
                $invoiceNumber = $this->getRecordValue($record, ['invoicenumber', 'invoice_number']);

                $row = [
                    'client_id' => $clientId,
                    'doctor_name' => $doctorName,
                    'item' => $itemName,
                    'tooth' => $tooth,
                    'status' => $status,
                    'sent_date' => $sentDate,
                    'due_date' => $dueDate,
                    'received_date' => $receivedDate,
                    'branch_id' => $branchId,
                    'shade' => $shade,
                    'material' => $material,
                    'instructions' => $instructions,
                    'applied_price' => $appliedPrice,
                    'invoice_number' => $invoiceNumber,
                    'updated_at' => $now,
                ];

                static $labOrderColumns = null;
                if ($labOrderColumns === null) {
                    $labOrderColumns = array_flip(Schema::getColumnListing('laboratory_orders'));
                }
                $row = array_intersect_key($row, $labOrderColumns);

                // Deduplication query: match item + sent_date + client_id + tooth
                $existingQuery = DB::table('laboratory_orders')
                    ->where('item', $itemName);

                if (isset($labOrderColumns['sent_date'])) {
                    $existingQuery->whereDate('sent_date', $sentDate);
                }

                if (isset($labOrderColumns['client_id'])) {
                    if ($clientId !== null) {
                        $existingQuery->where('client_id', $clientId);
                    } else {
                        $existingQuery->whereNull('client_id');
                    }
                }

                if (isset($labOrderColumns['tooth'])) {
                    if ($tooth !== null) {
                        $existingQuery->where('tooth', $tooth);
                    } else {
                        $existingQuery->whereNull('tooth');
                    }
                }

                $existing = $existingQuery->first();

                if ($existing) {
                    DB::table('laboratory_orders')->where('id', $existing->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    DB::table('laboratory_orders')->insert($row);
                }
                $result['imported']++;
            } catch (\Throwable $e) {
                Log::error('Lab order import error: ' . $e->getMessage(), [
                    'row' => $rowNumber,
                    'trace' => $e->getTraceAsString()
                ]);
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'item',
                    'value' => $this->getRecordValue($record, ['item', 'item_name']) ?? 'Lab order row',
                    'reason' => 'Failed to save lab order: ' . $e->getMessage()
                ];
                $result['failed']++;
            }
        }
    }

    /**
     * Import medical histories, resolving patient by email or phone.
     */
    private function processMedicalHistoryChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $found = $this->findPatientForRow($record, $patientsByEmail, $patientsByPhone);
            if (!$found['identifier']) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => null,
                    'reason' => 'Patient email or phone is required'
                ];
                $result['failed']++;
                continue;
            }

            $patient = $found['patient'];
            if (!$patient) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email/patient_phone',
                    'value' => $found['identifier'],
                    'reason' => "No existing patient found for {$found['identifier']}. Import the patient first."
                ];
                $result['failed']++;
                continue;
            }

            try {
            $rawDate = $this->getRecordValue($record, ['date', 'history_date', 'visit_date']);
            $date = $this->parseDate($rawDate, $now->format('Y-m-d'));

            $rawConsent = strtolower(trim($this->getRecordValue($record, ['consent']) ?? 'yes'));
            $consent = in_array($rawConsent, ['1', 'true', 'yes', 'y']);

            $doctorName = $this->getRecordValue($record, ['doctorname', 'doctor_name', 'doctor']) ?? 'Attending Doctor';
            $chiefComplaint = $this->getRecordValue($record, ['chiefcomplaint', 'chief_complaint', 'complaint']) ?? 'Routine checkup';

                DB::table('medical_histories')->insert([
                    'patient_id' => $patient->id,
                    'date' => $date,
                    'chief_complaint' => $chiefComplaint,
                    'history_complaint' => $this->getRecordValue($record, ['historycomplaint', 'history_complaint', 'history_of_complaint']),
                    'extra_oral_exam' => $this->getRecordValue($record, ['extraoralexam', 'extra_oral_exam']),
                    'intra_oral_exam' => $this->getRecordValue($record, ['intraoralexam', 'intra_oral_exam']),
                    'diagnosis' => $this->getRecordValue($record, ['diagnosis']),
                    'treatment_plan' => $this->getRecordValue($record, ['treatmentplan', 'treatment_plan']),
                    'treatment_given' => $this->getRecordValue($record, ['treatmentgiven', 'treatment_given']),
                    'consent' => $consent ? 1 : 0,
                    'consent_notes' => $this->getRecordValue($record, ['consentnotes', 'consent_notes']),
                    'treatment_note' => $this->getRecordValue($record, ['treatmentnote', 'treatment_note']),
                    'doctor_name' => $doctorName,
                    'doctor_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $result['imported']++;
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'medical_history',
                    'value' => $this->getRecordValue($record, ['chiefcomplaint', 'chief_complaint', 'complaint']) ?? 'Routine checkup',
                    'reason' => 'Failed to save medical history: ' . $e->getMessage()
                ];
                $result['failed']++;
            }
        }
    }

    private function processPayrollChunk($chunk, &$result, $now)
    {
        $employeeEmails = [];
        foreach ($chunk as $item) {
            $eEmail = $this->getRecordValue($item['data'], ['employeeemail', 'employee_email']);
            if ($eEmail) $employeeEmails[] = strtolower(trim($eEmail));
        }

        $employees = DB::table('employees')->whereIn('email', $employeeEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });

        $validStatuses = ['Paid', 'Pending'];
        $validApproval = ['Pending', 'Approved', 'Rejected'];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $eEmail = $this->getRecordValue($record, ['employeeemail', 'employee_email']);
            if (!$eEmail) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'employee_email',
                    'value' => null,
                    'reason' => 'Employee email is required'
                ];
                $result['failed']++;
                continue;
            }

            $eEmailNorm = strtolower(trim($eEmail));
            $e = $employees->get($eEmailNorm);
            if (!$e) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'employee_email',
                    'value' => $eEmailNorm,
                    'reason' => "No existing employee found for email {$eEmailNorm}. Import the employee first."
                ];
                $result['failed']++;
                continue;
            }

            $grossPay = $this->getRecordValue($record, ['grosspay', 'gross_pay']) ?? 0;
            $deductions = $this->getRecordValue($record, ['deductions']) ?? 0;
            if (!is_numeric($grossPay) || !is_numeric($deductions)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'gross_pay/deductions',
                    'value' => null,
                    'reason' => 'Gross pay and deductions must be numbers'
                ];
                $result['failed']++;
                continue;
            }

            $netPay = $this->getRecordValue($record, ['netpay', 'net_pay']);
            if (!is_numeric($netPay)) {
                $netPay = (float)$grossPay - (float)$deductions;
            }

            $payPeriod = $this->getRecordValue($record, ['payperiod', 'pay_period']) ?? '';

            $rawPayDate = $this->getRecordValue($record, ['paydate', 'pay_date']);
            $payDate = $rawPayDate ? date('Y-m-d', strtotime($rawPayDate)) : $now->format('Y-m-d');

            $status = $this->getRecordValue($record, ['status']) ?? 'Pending';
            $matchedStatus = null;
            foreach ($validStatuses as $validStatus) {
                if (strcasecmp($status, $validStatus) === 0) {
                    $matchedStatus = $validStatus;
                    break;
                }
            }
            $status = $matchedStatus ?? 'Pending';

            $approvalStatus = $this->getRecordValue($record, ['approvalstatus', 'approval_status']) ?? 'Approved';
            $matchedApproval = null;
            foreach ($validApproval as $validApprovalStatus) {
                if (strcasecmp($approvalStatus, $validApprovalStatus) === 0) {
                    $matchedApproval = $validApprovalStatus;
                    break;
                }
            }
            $approvalStatus = $matchedApproval ?? 'Approved';

            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? $e->branch_id ?? 1;

            $row = [
                'employee_id' => $e->id,
                'employee_name' => $e->name,
                'pay_period' => $payPeriod,
                'gross_pay' => (float)$grossPay,
                'deductions' => (float)$deductions,
                'net_pay' => (float)$netPay,
                'pay_date' => $payDate,
                'status' => $status,
                'branch_id' => $branchId,
                'approval_status' => $approvalStatus,
                'updated_at' => $now,
            ];

            // Match key: same employee + pay_period is treated as the same
            // payroll run (updated in place).
            try {
                $existing = DB::table('payrolls')
                    ->where('employee_id', $e->id)
                    ->where('pay_period', $payPeriod)
                    ->first();

                if ($existing) {
                    DB::table('payrolls')->where('id', $existing->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    DB::table('payrolls')->insert($row);
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Payroll import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'pay_period',
                    'value' => $payPeriod,
                    'reason' => 'Failed to save payroll record'
                ];
                $result['failed']++;
            }
        }
    }

    private function processLeaveRequestChunk($chunk, &$result, $now)
    {
        $employeeEmails = [];
        foreach ($chunk as $item) {
            $eEmail = $this->getRecordValue($item['data'], ['employeeemail', 'employee_email']);
            if ($eEmail) $employeeEmails[] = strtolower(trim($eEmail));
        }

        $employees = DB::table('employees')->whereIn('email', $employeeEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });

        $validStatuses = ['Pending', 'Approved', 'Rejected'];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $eEmail = $this->getRecordValue($record, ['employeeemail', 'employee_email']);
            if (!$eEmail) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'employee_email',
                    'value' => null,
                    'reason' => 'Employee email is required'
                ];
                $result['failed']++;
                continue;
            }

            $eEmailNorm = strtolower(trim($eEmail));
            $e = $employees->get($eEmailNorm);
            if (!$e) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'employee_email',
                    'value' => $eEmailNorm,
                    'reason' => "No existing employee found for email {$eEmailNorm}. Import the employee first."
                ];
                $result['failed']++;
                continue;
            }

            $leaveType = $this->getRecordValue($record, ['leavetype', 'leave_type']) ?? 'Other';

            $rawStart = $this->getRecordValue($record, ['startdate', 'start_date']);
            $startDate = $rawStart ? date('Y-m-d', strtotime($rawStart)) : $now->format('Y-m-d');

            $rawEnd = $this->getRecordValue($record, ['enddate', 'end_date']);
            $endDate = $rawEnd ? date('Y-m-d', strtotime($rawEnd)) : $startDate;

            $status = $this->getRecordValue($record, ['status']) ?? 'Approved';
            $matchedStatus = null;
            foreach ($validStatuses as $validStatus) {
                if (strcasecmp($status, $validStatus) === 0) {
                    $matchedStatus = $validStatus;
                    break;
                }
            }
            $status = $matchedStatus ?? 'Approved';

            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? $e->branch_id ?? 1;

            $row = [
                'employee_id' => $e->id,
                'employee_name' => $e->name,
                'leave_type' => $leaveType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $this->getRecordValue($record, ['reason']),
                'status' => $status,
                'branch_id' => $branchId,
                'updated_at' => $now,
            ];

            // Match key: same employee + leave_type + start_date + end_date is
            // treated as the same leave request (updated in place).
            try {
                $existing = DB::table('leave_requests')
                    ->where('employee_id', $e->id)
                    ->where('leave_type', $leaveType)
                    ->whereDate('start_date', $startDate)
                    ->whereDate('end_date', $endDate)
                    ->first();

                if ($existing) {
                    DB::table('leave_requests')->where('id', $existing->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    DB::table('leave_requests')->insert($row);
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Leave request import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'leave_type',
                    'value' => $leaveType,
                    'reason' => 'Failed to save leave request'
                ];
                $result['failed']++;
            }
        }
    }

    private function processStaffAdvanceChunk($chunk, &$result, $now)
    {
        $employeeEmails = [];
        foreach ($chunk as $item) {
            $eEmail = $this->getRecordValue($item['data'], ['employeeemail', 'employee_email']);
            if ($eEmail) $employeeEmails[] = strtolower(trim($eEmail));
        }

        $employees = DB::table('employees')->whereIn('email', $employeeEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });

        $validStatuses = ['Pending', 'Approved', 'Rejected'];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $eEmail = $this->getRecordValue($record, ['employeeemail', 'employee_email']);
            if (!$eEmail) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'employee_email',
                    'value' => null,
                    'reason' => 'Employee email is required'
                ];
                $result['failed']++;
                continue;
            }

            $eEmailNorm = strtolower(trim($eEmail));
            $e = $employees->get($eEmailNorm);
            if (!$e) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'employee_email',
                    'value' => $eEmailNorm,
                    'reason' => "No existing employee found for email {$eEmailNorm}. Import the employee first."
                ];
                $result['failed']++;
                continue;
            }

            $amount = $this->getRecordValue($record, ['amount']) ?? 0;
            if (!is_numeric($amount) || (float)$amount < 0) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'amount',
                    'value' => $amount,
                    'reason' => 'Amount must be a positive number'
                ];
                $result['failed']++;
                continue;
            }

            $rawDate = $this->getRecordValue($record, ['date']);
            $date = $rawDate ? date('Y-m-d', strtotime($rawDate)) : $now->format('Y-m-d');

            $status = $this->getRecordValue($record, ['status']) ?? 'Approved';
            $matchedStatus = null;
            foreach ($validStatuses as $validStatus) {
                if (strcasecmp($status, $validStatus) === 0) {
                    $matchedStatus = $validStatus;
                    break;
                }
            }
            $status = $matchedStatus ?? 'Approved';

            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? $e->branch_id ?? 1;

            $row = [
                'employee_id' => $e->id,
                'employee_name' => $e->name,
                'amount' => (float)$amount,
                'date' => $date,
                'reason' => $this->getRecordValue($record, ['reason']),
                'status' => $status,
                'branch_id' => $branchId,
                'updated_at' => $now,
            ];

            // Match key: same employee + date is treated as the same advance
            // (updated in place) - an employee is not expected to take two
            // separate advances on the same day.
            try {
                $existing = DB::table('staff_advances')
                    ->where('employee_id', $e->id)
                    ->whereDate('date', $date)
                    ->first();

                if ($existing) {
                    DB::table('staff_advances')->where('id', $existing->id)->update($row);
                } else {
                    $row['created_at'] = $now;
                    DB::table('staff_advances')->insert($row);
                }
                $result['imported']++;
            } catch (\Exception $e) {
                Log::error('Staff advance import error: ' . $e->getMessage());
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'date',
                    'value' => $date,
                    'reason' => 'Failed to save staff advance'
                ];
                $result['failed']++;
            }
        }
    }

    // Validation helpers
    private function validateEmail($email)
    {
        if (empty($email)) {
            return ['valid' => false, 'reason' => 'Email is required'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'reason' => 'Invalid email format'];
        }
        if (strlen($email) > 255) {
            return ['valid' => false, 'reason' => 'Email too long (max 255 characters)'];
        }
        return ['valid' => true];
    }

    private function validatePhone($phone)
    {
        if (empty($phone)) {
            return ['valid' => false, 'reason' => 'Phone is required'];
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 10) {
            return ['valid' => false, 'reason' => 'Phone number too short (minimum 10 digits)'];
        }
        if (strlen($digits) > 20) {
            return ['valid' => false, 'reason' => 'Phone number too long (maximum 20 digits)'];
        }
        return ['valid' => true];
    }

    private function validateDate($date, $allowFuture = false)
    {
        if (empty($date)) {
            return ['valid' => false, 'reason' => 'Date is required'];
        }

        $parsed = strtotime($date);
        if ($parsed === false) {
            return ['valid' => false, 'reason' => "Invalid date format: {$date}"];
        }

        if (!$allowFuture && $parsed > time()) {
            return ['valid' => false, 'reason' => 'Date cannot be in the future'];
        }

        return ['valid' => true];
    }

    /**
     * Handle combined CSV with type column for all sections in one file
     *
     * Processes types in dependency order to guarantee referential integrity:
     * 1. Employees (no dependencies)
     * 2. Clients (no dependencies)
     * 3. Patients (no dependencies)
     * 4. Odontogram / Periodontal charts (references patients; upserted per tooth)
     * 5. Orthodontic / Endodontic charts (references patients; append-only history)
     * 6. Appointments (references employees & patients)
     * 7. Clinical (references patients; optional 'ref' column for invoice linking)
     * 8. Financial (references patients; optional 'procedure_refs' linking to clinical rows)
     * 9. Lab orders (reference clients & employees)
     * 10. Payroll / Leave requests / Staff advances (reference employees)
     * 11. Insurance company (no dependencies)
     * 12. Insurance package (references insurance company)
     * 13. Insurance coverage (references insurance package & the treatment catalog)
     * 14. Insurance policy (references insurance package)
     * 15. Insurance enrollment (references patients & an insurance policy - either
     *     pre-existing or created by steps 11-14 above)
     * 16. Claims (reference the insurance enrollment created above)
     * 17. Payments (reference an invoice via 'invoice_ref' matching a financial
     *     row's 'ref', and optionally a claim via 'claim_number' from step 16)
     */
    public function migrateCombined(Request $request)
    {
        try {
            set_time_limit(0);
            $startTime = microtime(true);
            $importId = 'imp_' . uniqid();
            $this->clinicalRefMap = [];
            $this->financialRefMap = [];

            Log::info('Combined migration started', ['import_id' => $importId]);

            // Validate request
            try {
                $request->validate([
                    'file' => 'required|file|mimes:csv,txt,xlsx'
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Validation error', ['errors' => $e->errors()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors()
                ], 422);
            }

            $file = $request->file('file');
        $originalExtension = strtolower($file->getClientOriginalExtension());
        $filePath = $file->getPathname();
        $isTempCsv = false;

        // Convert Excel to CSV if needed
        if ($originalExtension === 'xlsx') {
            // openpyxl validates the file by its extension, and Laravel's upload
            // tmp path (e.g. /tmp/phpXXXXXX) has none, so give it a proper .xlsx name.
            $tempXlsxPath = tempnam(sys_get_temp_dir(), 'xlsx_migrate_') . '.xlsx';
            copy($filePath, $tempXlsxPath);

            $tempCsvPath = tempnam(sys_get_temp_dir(), 'xlsx_migrate_') . '.csv';
            $pythonScript = base_path('app/Utils/excel_to_csv.py');
            // --merge-sheets: the combined template can split its rows across one
            // sheet per type (Employees, Patients, ...) instead of one wide sheet;
            // every sheet except "Instructions" is merged by column name.
            $pythonBin = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
            $command = "{$pythonBin} " . escapeshellarg($pythonScript) . " " . escapeshellarg($tempXlsxPath) . " " . escapeshellarg($tempCsvPath) . " --merge-sheets 2>&1";

            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            // If first attempt failed, try fallback binary
            if ($resultCode !== 0) {
                $fallbackBin = $pythonBin === 'python3' ? 'python' : 'python3';
                $command = "{$fallbackBin} " . escapeshellarg($pythonScript) . " " . escapeshellarg($tempXlsxPath) . " " . escapeshellarg($tempCsvPath) . " --merge-sheets 2>&1";
                exec($command, $output, $resultCode);
            }
            unlink($tempXlsxPath);

            if ($resultCode === 0) {
                $filePath = $tempCsvPath;
                $isTempCsv = true;
            } else {
                Log::error('Excel to CSV conversion failed', [
                    'import_id' => $importId,
                    'result_code' => $resultCode,
                    'output' => implode("\n", $output)
                ]);
                return $this->errorResponse($importId, 0, 0, [
                    ['row' => 0, 'field' => 'file', 'value' => null, 'reason' => 'Failed to parse Excel file']
                ], $startTime);
            }
        }

        $handle = fopen($filePath, "r");
        $headers = fgetcsv($handle);

        if (!$headers) {
            fclose($handle);
            if ($isTempCsv && file_exists($filePath)) unlink($filePath);

            return $this->errorResponse($importId, 0, 0, [
                ['row' => 1, 'field' => 'file', 'value' => null, 'reason' => 'File is empty or has no headers']
            ], $startTime);
        }

        // Verify 'type' column exists
        $typeIndex = array_search('type', array_map('strtolower', $headers));
        if ($typeIndex === false) {
            fclose($handle);
            if ($isTempCsv && file_exists($filePath)) unlink($filePath);

            return $this->errorResponse($importId, 0, 0, [
                ['row' => 1, 'field' => 'type', 'value' => null, 'reason' => 'CSV must have a "type" column (employee, client, patient, appointment, clinical, financial, lab_order, payroll, leave_request, staff_advance, odontogram, periodontal, orthodontic, endodontic, insurance_company, insurance_package, insurance_coverage, insurance_policy, insurance, claim, payment)']
            ], $startTime);
        }

        $headers = array_map('trim', $headers);

        // Group data by type while reading
        $groupedData = [
            'employees' => [],
            'clients' => [],
            'patients' => [],
            'appointments' => [],
            'clinical' => [],
            'financial' => [],
            'lab_order' => [],
            'payroll' => [],
            'leave_request' => [],
            'staff_advance' => [],
            'odontogram' => [],
            'periodontal' => [],
            'orthodontic' => [],
            'endodontic' => [],
            'insurance_company' => [],
            'insurance_package' => [],
            'insurance_coverage' => [],
            'insurance_policy' => [],
            'insurance' => [],
            'claim' => [],
            'medical_history' => [],
            'payment' => []
        ];

        $rowNumber = 2;
        $totalCount = 0;
        $allErrors = [];
        $allWarnings = [];

        while (($row = fgetcsv($handle)) !== false) {
            // Skip empty rows
            if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                $rowNumber++;
                continue;
            }

            // Pad row if needed
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }

            $recordData = array_combine($headers, array_slice($row, 0, count($headers)));
            $rawType = strtolower(trim($recordData['type'] ?? ''));

            // Normalize raw type string (strip underscores, hyphens, and spaces for matching)
            $normType = str_replace(['_', '-', ' '], '', $rawType);

            $typeMap = [
                'employee' => 'employees',
                'employees' => 'employees',
                'client' => 'clients',
                'clients' => 'clients',
                'patient' => 'patients',
                'patients' => 'patients',
                'appointment' => 'appointments',
                'appointments' => 'appointments',
                'clinical' => 'clinical',
                'financial' => 'financial',
                'invoice' => 'financial',
                'invoices' => 'financial',
                'laborder' => 'lab_order',
                'laborders' => 'lab_order',
                'payroll' => 'payroll',
                'payrolls' => 'payroll',
                'leaverequest' => 'leave_request',
                'leaverequests' => 'leave_request',
                'staffadvance' => 'staff_advance',
                'staffadvances' => 'staff_advance',
                'odontogram' => 'odontogram',
                'periodontal' => 'periodontal',
                'periodontalchart' => 'periodontal',
                'orthodontic' => 'orthodontic',
                'orthodonticchart' => 'orthodontic',
                'endodontic' => 'endodontic',
                'endodonticchart' => 'endodontic',
                'insurancecompany' => 'insurance_company',
                'insurancecompanies' => 'insurance_company',
                'insurancepackage' => 'insurance_package',
                'insurancepackages' => 'insurance_package',
                'insurancecoverage' => 'insurance_coverage',
                'insurancepolicy' => 'insurance_policy',
                'insurancepolicies' => 'insurance_policy',
                'insurance' => 'insurance',
                'insuranceenrollment' => 'insurance',
                'claim' => 'claim',
                'claims' => 'claim',
                'medicalhistory' => 'medical_history',
                'medicalhistories' => 'medical_history',
                'medical_history' => 'medical_history',
                'payment' => 'payment',
                'payments' => 'payment',
            ];
            $type = $typeMap[$normType] ?? null;

            if ($type === null) {
                $allWarnings[] = "Row {$rowNumber}: Unrecognized type \"{$rawType}\" (skipped)";
                $rowNumber++;
                continue;
            }

            $groupedData[$type][] = [
                'rowNumber' => $rowNumber,
                'data' => $recordData
            ];
            $totalCount++;
            $rowNumber++;
        }

        fclose($handle);
        if ($isTempCsv && file_exists($filePath)) {
            unlink($filePath);
        }

        // Process in dependency order
        $importedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $now = now();

        // Order matters: employees/patients first, then things that reference them,
        // then the insurance setup chain (company -> package -> coverage -> policy),
        // then insurance enrollment, then claims (which need the enrollment to exist)
        $processingOrder = [
            'employees', 'clients', 'patients',
            'medical_history',
            'odontogram', 'periodontal', 'orthodontic', 'endodontic',
            'appointments', 'clinical', 'financial',
            'lab_order', 'payroll', 'leave_request', 'staff_advance',
            'insurance_company', 'insurance_package', 'insurance_coverage', 'insurance_policy',
            'insurance', 'claim',
            'payment',
        ];

        foreach ($processingOrder as $section) {
            if (empty($groupedData[$section])) {
                continue; // Skip empty sections
            }

            // Process in chunks
            $chunk = [];
            $chunkSize = 1000;

            foreach ($groupedData[$section] as $item) {
                $chunk[] = $item;

                if (count($chunk) >= $chunkSize) {
                    $result = $this->processChunk($section, $chunk);
                    $importedCount += $result['imported'];
                    $failedCount += $result['failed'];
                    $skippedCount += $result['skipped'];
                    $allErrors = array_merge($allErrors, $result['errors']);
                    $allWarnings = array_merge($allWarnings, $result['warnings']);
                    $chunk = [];
                }
            }

            // Process remaining
            if (count($chunk) > 0) {
                $result = $this->processChunk($section, $chunk);
                $importedCount += $result['imported'];
                $failedCount += $result['failed'];
                $skippedCount += $result['skipped'];
                $allErrors = array_merge($allErrors, $result['errors']);
                $allWarnings = array_merge($allWarnings, $result['warnings']);
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $success = $failedCount === 0;

        return response()->json([
            'success' => $success,
            'stats' => [
                'total' => $totalCount,
                'imported' => $importedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
                'duration' => $duration
            ],
            'errors' => array_slice($allErrors, 0, 100),
            'warnings' => array_slice($allWarnings, 0, 50),
            'import_id' => $importId,
            'import_type' => 'combined'
        ], $success ? 200 : 400);
        } catch (\Throwable $e) {
            Log::error('Combined migration error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage(),
                'error' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    private function getRecordValue($record, $synonyms)
    {
        $lookup = [];
        foreach ($record as $key => $value) {
            $norm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key));
            $lookup[$norm] = $value;
        }

        foreach ($synonyms as $synonym) {
            $normSyn = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $synonym));
            if (array_key_exists($normSyn, $lookup)) {
                return $lookup[$normSyn];
            }
        }

        return null;
    }

    public function downloadTemplate()
    {
        try {
            $templatePath = base_path('public/templates/combined-migration-template.xlsx');

            Log::info('Template download attempt', ['path' => $templatePath]);

            if (!file_exists($templatePath)) {
                Log::error('Template file not found', ['path' => $templatePath, 'exists' => false]);
                return response()->json([
                    'success' => false,
                    'message' => 'Template file not found',
                    'path_checked' => $templatePath
                ], 404);
            }

            if (!is_file($templatePath)) {
                Log::error('Template path is not a file', ['path' => $templatePath, 'is_file' => false]);
                return response()->json([
                    'success' => false,
                    'message' => 'Template path is not a file'
                ], 400);
            }

            if (!is_readable($templatePath)) {
                Log::error('Template file not readable', ['path' => $templatePath]);
                return response()->json([
                    'success' => false,
                    'message' => 'Template file not accessible'
                ], 403);
            }

            Log::info('Template download successful', [
                'file' => 'combined-migration-template.xlsx',
                'size' => filesize($templatePath)
            ]);

            return response()->download(
                $templatePath,
                'combined-migration-template.xlsx'
            );
        } catch (\Throwable $e) {
            Log::error('Template download error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to download template: ' . $e->getMessage()
            ], 500);
        }
    }
}
