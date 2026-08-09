<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    public function migrate(Request $request)
    {
        set_time_limit(0);
        $startTime = microtime(true);
        $importId = 'imp_' . uniqid();
        $this->clinicalRefMap = [];

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx',
            'section' => 'required|string|in:employees,patients,appointments,clinical,financial,insurance,claim,client,lab_order,payroll,leave_request,staff_advance,insurance_company,insurance_package,insurance_coverage,insurance_policy'
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
            $command = "python3 " . escapeshellarg($pythonScript) . " " . escapeshellarg($tempXlsxPath) . " " . escapeshellarg($tempCsvPath) . " 2>&1";

            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);
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
        } elseif ($section === 'financial') {
            $this->processFinancialChunk($chunk, $result, $now);
        } elseif ($section === 'insurance') {
            $this->processInsuranceChunk($chunk, $result, $now);
        } elseif ($section === 'claim') {
            $this->processClaimChunk($chunk, $result, $now);
        } elseif ($section === 'client' || $section === 'clients') {
            $this->processClientChunk($chunk, $result, $now);
        } elseif ($section === 'lab_order') {
            $this->processLabOrderChunk($chunk, $result, $now);
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

    private function parseBool($value, $default = false)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
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
            $pEmail = $this->getRecordValue($item['data'], ['patientemail', 'patient_email']);
            if ($pEmail) $emails[] = strtolower(trim($pEmail));
            $pPhone = $this->getRecordValue($item['data'], ['patientphone', 'patient_phone']);
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
        $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
        if (!empty($pEmail)) {
            return [
                'patient' => $byEmail->get(strtolower(trim($pEmail))),
                'identifier' => "email {$pEmail}",
            ];
        }

        $pPhone = $this->getRecordValue($record, ['patientphone', 'patient_phone']);
        if (!empty($pPhone)) {
            return [
                'patient' => $byPhone->get($this->normalizePhone($pPhone)),
                'identifier' => "phone {$pPhone}",
            ];
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
                'email' => $email,
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
                $phoneOnlyData[] = $row;
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
            $normalizedPhones = array_map(function ($row) {
                return $this->normalizePhone($row['phone']);
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

            foreach ($phoneOnlyData as $row) {
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
                    $result['failed']++;
                    $result['imported']--;
                }
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

        $insertData = [];

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

            $insertData[] = [
                'patient_id' => $p->id,
                'patient_name' => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')),
                'doctor_id' => $d->id,
                'doctor_name' => $d->name,
                'date' => $date,
                'time' => $time,
                'treatment' => $treatment,
                'status' => $status,
                'branch_id' => $branchId,
                'token_number' => 'TKN-' . rand(1000, 9999),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($insertData)) {
            try {
                DB::table('appointments')->insert($insertData);
            } catch (\Exception $e) {
                Log::error('Appointment import error: ' . $e->getMessage());
                $result['failed'] += count($insertData);
                $result['imported'] -= count($insertData);
            }
        }
    }

    private function processClinicalChunk($chunk, &$result, $now)
    {
        [$patientsByEmail, $patientsByPhone] = $this->buildPatientLookupMaps($chunk);

        $insertData = [];

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

            $row = [
                'patient_id' => $p->id,
                'procedure' => $procedure,
                'tooth' => $tooth,
                'status' => $status,
                'cost' => (float)$cost,
                'date' => $date,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Rows with a 'ref' are inserted individually so the new id can be
            // captured and matched by a later 'financial' row's procedure_refs.
            $ref = $this->getRecordValue($record, ['ref', 'reference', 'row_ref', 'external_id']);

            if (!empty($ref)) {
                try {
                    $id = DB::table('treatment_plans')->insertGetId($row);
                    $this->clinicalRefMap[trim($ref)] = [
                        'id' => $id,
                        'procedure' => $procedure,
                        'cost' => (float)$cost,
                        'patient_id' => $p->id,
                    ];
                    $result['imported']++;
                } catch (\Exception $e) {
                    Log::error('Clinical import error (ref row): ' . $e->getMessage());
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'field' => 'ref',
                        'value' => $ref,
                        'reason' => 'Failed to insert treatment plan row'
                    ];
                    $result['failed']++;
                }
            } else {
                $insertData[] = $row;
                $result['imported']++;
            }
        }

        if (!empty($insertData)) {
            try {
                DB::table('treatment_plans')->insert($insertData);
            } catch (\Exception $e) {
                Log::error('Clinical import error: ' . $e->getMessage());
                $result['failed'] += count($insertData);
                $result['imported'] -= count($insertData);
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

            $status = $this->getRecordValue($record, ['status']) ?? 'Pending';
            if (strcasecmp($status, 'Paid') === 0) {
                $status = 'Paid';
            } elseif (strcasecmp($status, 'Overdue') === 0) {
                $status = 'Overdue';
            } else {
                $status = 'Pending';
            }

            $paidAmount = ($status === 'Paid') ? (float)$amount : 0;

            $rawDate = $this->getRecordValue($record, ['date', 'issuedate', 'issue_date']);
            $issueDate = $rawDate ? date('Y-m-d', strtotime($rawDate)) : $now->format('Y-m-d');

            $rawDueDate = $this->getRecordValue($record, ['duedate', 'due_date']);
            $dueDate = $rawDueDate ? date('Y-m-d', strtotime($rawDueDate)) : date('Y-m-d', strtotime($issueDate . ' +14 days'));

            $paymentMethod = ($status === 'Paid') ? 'Cash' : null;

            // Optional label for the fallback line item when no procedure_refs match.
            $description = $this->getRecordValue($record, ['description', 'procedure', 'treatment']) ?? 'Migrated Treatment';

            // Optional comma/semicolon-separated list of clinical row 'ref' values
            // to attach as real invoice_treatments lines instead of a generic one.
            $refsRaw = $this->getRecordValue($record, ['procedure_refs', 'procedurerefs', 'refs', 'treatment_refs']);

            $patientBalance = max(0, (float)$amount - $paidAmount);

            $invoiceRow = [
                'patient_id' => $p->id,
                'patient_name' => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')),
                'amount' => (float)$amount,
                'paid_amount' => $paidAmount,
                'patient_paid_amount' => $paidAmount,
                'insurance_paid_amount' => 0,
                'status' => $status,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'branch_id' => $branchId,
                'payment_method' => $paymentMethod,
                'patient_responsibility' => (float)$amount,
                'insurance_responsibility' => 0,
                'patient_balance' => $patientBalance,
                'insurance_balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            try {
                $invoiceId = DB::table('invoices')->insertGetId($invoiceRow);

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
    private function processLabOrderChunk($chunk, &$result, $now)
    {
        $doctorEmails = [];
        $clientNames = [];
        foreach ($chunk as $item) {
            $dEmail = $this->getRecordValue($item['data'], ['doctoremail', 'doctor_email']);
            if ($dEmail) $doctorEmails[] = strtolower(trim($dEmail));
            $clientName = $this->getRecordValue($item['data'], ['client', 'clientname', 'client_name']);
            if ($clientName) $clientNames[] = trim($clientName);
        }

        $doctors = DB::table('employees')->whereIn('email', $doctorEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });
        $clients = DB::table('clients')->whereIn('name', $clientNames)->get()->keyBy(function ($item) {
            return strtolower(trim($item->name));
        });

        $insertData = [];
        $validStatuses = ['Sent', 'Received', 'Completed', 'Cancelled'];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $patientName = $this->getRecordValue($record, ['patientname', 'patient_name']);
            $lab = $this->getRecordValue($record, ['lab']);
            $itemName = $this->getRecordValue($record, ['item']);

            if (empty($patientName) || empty($lab) || empty($itemName)) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_name/lab/item',
                    'value' => null,
                    'reason' => 'Patient name, lab, and item are required'
                ];
                $result['failed']++;
                continue;
            }

            $clientId = null;
            $clientName = $this->getRecordValue($record, ['client', 'clientname', 'client_name']);
            if (!empty($clientName)) {
                $client = $clients->get(strtolower(trim($clientName)));
                if (!$client) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'field' => 'client',
                        'value' => $clientName,
                        'reason' => "No existing client found named \"{$clientName}\". Import the client first."
                    ];
                    $result['failed']++;
                    continue;
                }
                $clientId = $client->id;
            }

            $doctorId = null;
            $dEmail = $this->getRecordValue($record, ['doctoremail', 'doctor_email']);
            if (!empty($dEmail)) {
                $doctor = $doctors->get(strtolower(trim($dEmail)));
                if (!$doctor) {
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'field' => 'doctor_email',
                        'value' => $dEmail,
                        'reason' => "No existing employee found for email {$dEmail}. Import the employee first."
                    ];
                    $result['failed']++;
                    continue;
                }
                $doctorId = $doctor->id;
            }

            $rawTooth = $this->getRecordValue($record, ['tooth', 'tooth_number', 'toothnumber', 'tooth_no', 'toothno']);
            $toothValidation = $this->validateTooth($rawTooth);
            if (!$toothValidation['valid']) {
                $result['warnings'][] = "Row {$rowNumber}: {$toothValidation['reason']} (tooth left blank)";
            }

            $status = $this->getRecordValue($record, ['status']) ?? 'Sent';
            $matchedStatus = null;
            foreach ($validStatuses as $validStatus) {
                if (strcasecmp($status, $validStatus) === 0) {
                    $matchedStatus = $validStatus;
                    break;
                }
            }
            $status = $matchedStatus ?? 'Sent';

            $rawSentDate = $this->getRecordValue($record, ['sentdate', 'sent_date']);
            $sentDate = $rawSentDate ? date('Y-m-d', strtotime($rawSentDate)) : $now->format('Y-m-d');

            $rawDueDate = $this->getRecordValue($record, ['duedate', 'due_date']);
            $dueDate = $rawDueDate ? date('Y-m-d', strtotime($rawDueDate)) : date('Y-m-d', strtotime($sentDate . ' +7 days'));

            $rawReceivedDate = $this->getRecordValue($record, ['receiveddate', 'received_date']);
            $receivedDate = $rawReceivedDate ? date('Y-m-d', strtotime($rawReceivedDate)) : null;

            $cost = $this->getRecordValue($record, ['cost']);
            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

            $insertData[] = [
                'patient_name' => $patientName,
                'lab' => $lab,
                'item' => $itemName,
                'tooth' => $toothValidation['value'],
                'status' => $status,
                'sent_date' => $sentDate,
                'due_date' => $dueDate,
                'branch_id' => $branchId,
                'client_id' => $clientId,
                'doctor_id' => $doctorId,
                'received_date' => $receivedDate,
                'shade' => $this->getRecordValue($record, ['shade']),
                'material' => $this->getRecordValue($record, ['material']),
                'instructions' => $this->getRecordValue($record, ['instructions']),
                'cost' => is_numeric($cost) ? (float)$cost : null,
                'invoice_number' => $this->getRecordValue($record, ['invoicenumber', 'invoice_number']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($insertData)) {
            try {
                DB::table('laboratory_orders')->insert($insertData);
            } catch (\Exception $e) {
                Log::error('Lab order import error: ' . $e->getMessage());
                $result['failed'] += count($insertData);
                $result['imported'] -= count($insertData);
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

        $insertData = [];
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

            $insertData[] = [
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
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($insertData)) {
            try {
                DB::table('payrolls')->insert($insertData);
            } catch (\Exception $e) {
                Log::error('Payroll import error: ' . $e->getMessage());
                $result['failed'] += count($insertData);
                $result['imported'] -= count($insertData);
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

        $insertData = [];
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

            $insertData[] = [
                'employee_id' => $e->id,
                'employee_name' => $e->name,
                'leave_type' => $leaveType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $this->getRecordValue($record, ['reason']),
                'status' => $status,
                'branch_id' => $branchId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($insertData)) {
            try {
                DB::table('leave_requests')->insert($insertData);
            } catch (\Exception $e) {
                Log::error('Leave request import error: ' . $e->getMessage());
                $result['failed'] += count($insertData);
                $result['imported'] -= count($insertData);
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

        $insertData = [];
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

            $insertData[] = [
                'employee_id' => $e->id,
                'employee_name' => $e->name,
                'amount' => (float)$amount,
                'date' => $date,
                'reason' => $this->getRecordValue($record, ['reason']),
                'status' => $status,
                'branch_id' => $branchId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($insertData)) {
            try {
                DB::table('staff_advances')->insert($insertData);
            } catch (\Exception $e) {
                Log::error('Staff advance import error: ' . $e->getMessage());
                $result['failed'] += count($insertData);
                $result['imported'] -= count($insertData);
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
     * 4. Appointments (references employees & patients)
     * 5. Clinical (references patients; optional 'ref' column for invoice linking)
     * 6. Financial (references patients; optional 'procedure_refs' linking to clinical rows)
     * 7. Lab orders (reference clients & employees)
     * 8. Payroll / Leave requests / Staff advances (reference employees)
     * 9. Insurance company (no dependencies)
     * 10. Insurance package (references insurance company)
     * 11. Insurance coverage (references insurance package & the treatment catalog)
     * 12. Insurance policy (references insurance package)
     * 13. Insurance enrollment (references patients & an insurance policy - either
     *     pre-existing or created by steps 9-12 above)
     * 14. Claims (reference the insurance enrollment created above)
     */
    public function migrateCombined(Request $request)
    {
        try {
            set_time_limit(0);
            $startTime = microtime(true);
            $importId = 'imp_' . uniqid();
            $this->clinicalRefMap = [];

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
            $command = "python3 " . escapeshellarg($pythonScript) . " " . escapeshellarg($tempXlsxPath) . " " . escapeshellarg($tempCsvPath) . " --merge-sheets 2>&1";

            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);
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
                ['row' => 1, 'field' => 'type', 'value' => null, 'reason' => 'CSV must have a "type" column (employee, client, patient, appointment, clinical, financial, lab_order, payroll, leave_request, staff_advance, insurance_company, insurance_package, insurance_coverage, insurance_policy, insurance, claim)']
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
            'insurance_company' => [],
            'insurance_package' => [],
            'insurance_coverage' => [],
            'insurance_policy' => [],
            'insurance' => [],
            'claim' => []
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

            // The 'type' column uses singular values (employee, patient, appointment,
            // clinical, financial); $groupedData is keyed by table name (plural for
            // employees/patients/appointments, singular for clinical/financial).
            $typeMap = [
                'employee' => 'employees',
                'client' => 'clients',
                'patient' => 'patients',
                'appointment' => 'appointments',
                'clinical' => 'clinical',
                'financial' => 'financial',
                'lab_order' => 'lab_order',
                'payroll' => 'payroll',
                'leave_request' => 'leave_request',
                'staff_advance' => 'staff_advance',
                'insurance_company' => 'insurance_company',
                'insurance_package' => 'insurance_package',
                'insurance_coverage' => 'insurance_coverage',
                'insurance_policy' => 'insurance_policy',
                'insurance' => 'insurance',
                'claim' => 'claim',
            ];
            $type = $typeMap[$rawType] ?? null;

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
            'employees', 'clients', 'patients', 'appointments', 'clinical', 'financial',
            'lab_order', 'payroll', 'leave_request', 'staff_advance',
            'insurance_company', 'insurance_package', 'insurance_coverage', 'insurance_policy',
            'insurance', 'claim',
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
