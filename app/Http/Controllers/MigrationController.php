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
    public function migrate(Request $request)
    {
        set_time_limit(0);
        $startTime = microtime(true);
        $importId = 'imp_' . uniqid();

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx',
            'section' => 'required|string|in:employees,patients,appointments,clinical,financial'
        ]);

        $section = $request->input('section');
        $file = $request->file('file');

        $originalExtension = strtolower($file->getClientOriginalExtension());
        $filePath = $file->getPathname();
        $isTempCsv = false;

        // Convert Excel to CSV if needed
        if ($originalExtension === 'xlsx') {
            $tempCsvPath = tempnam(sys_get_temp_dir(), 'xlsx_migrate_') . '.csv';
            $pythonScript = base_path('app/Utils/excel_to_csv.py');
            $command = "python " . escapeshellarg($pythonScript) . " " . escapeshellarg($filePath) . " " . escapeshellarg($tempCsvPath);

            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            if ($resultCode === 0) {
                $filePath = $tempCsvPath;
                $isTempCsv = true;
            } else {
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
        }

        return $result;
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
        $duplicateEmails = [];

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

            // Check for duplicates
            if (!empty($email) && in_array($email, $duplicateEmails)) {
                $result['warnings'][] = "Row {$rowNumber}: Duplicate email (skipped)";
                $result['skipped']++;
                continue;
            }
            if (!empty($email)) $duplicateEmails[] = $email;

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

            $upsertData[] = [
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
    }

    private function processAppointmentChunk($chunk, &$result, $now)
    {
        $patientEmails = [];
        $doctorEmails = [];

        foreach ($chunk as $item) {
            $record = $item['data'];
            $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
            if ($pEmail) $patientEmails[] = strtolower(trim($pEmail));
            $dEmail = $this->getRecordValue($record, ['doctoremail', 'doctor_email']);
            if ($dEmail) $doctorEmails[] = strtolower(trim($dEmail));
        }

        $patients = DB::table('patients')->whereIn('email', $patientEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });
        $doctors = DB::table('employees')->whereIn('email', $doctorEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });

        $insertData = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
            $dEmail = $this->getRecordValue($record, ['doctoremail', 'doctor_email']);

            if (!$pEmail || !$dEmail) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient/doctor email',
                    'value' => null,
                    'reason' => 'Both patient and doctor email are required'
                ];
                $result['failed']++;
                continue;
            }

            $pEmailNorm = strtolower(trim($pEmail));
            $dEmailNorm = strtolower(trim($dEmail));

            $p = $patients->get($pEmailNorm);
            $d = $doctors->get($dEmailNorm);
            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

            if (!$p) {
                $pPhone = $this->getRecordValue($record, ['patientphone', 'patient_phone', 'phone']);
                $pId = DB::table('patients')->insertGetId([
                    'first_name' => 'Imported',
                    'last_name' => 'Patient',
                    'email' => $pEmailNorm,
                    'phone' => $pPhone,
                    'date_of_birth' => '1990-01-01',
                    'gender' => 'Other',
                    'status' => 'Active',
                    'branch_id' => $branchId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $p = (object)['id' => $pId, 'first_name' => 'Imported', 'last_name' => 'Patient', 'email' => $pEmailNorm];
                $patients->put($pEmailNorm, $p);
                $result['warnings'][] = "Row {$rowNumber}: Created patient for email {$pEmailNorm}";
            }

            if (!$d) {
                $dId = DB::table('employees')->insertGetId([
                    'name' => 'Imported Doctor',
                    'email' => $dEmailNorm,
                    'role' => 'Dentist',
                    'role_id' => 4,
                    'salary' => 0,
                    'hire_date' => $now->format('Y-m-d'),
                    'status' => 'Active',
                    'branch_id' => $branchId,
                    'password' => Hash::make('password'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $d = (object)['id' => $dId, 'name' => 'Imported Doctor', 'email' => $dEmailNorm];
                $doctors->put($dEmailNorm, $d);
                $result['warnings'][] = "Row {$rowNumber}: Created doctor for email {$dEmailNorm}";
            }

            $rawDate = $this->getRecordValue($record, ['appointmentdate', 'appointment_date', 'date']);
            $dateTime = $rawDate ? strtotime($rawDate) : false;
            $date = $dateTime ? date('Y-m-d', $dateTime) : $now->format('Y-m-d');
            $time = $dateTime ? date('H:i', $dateTime) : '10:00';

            $status = $this->getRecordValue($record, ['status']) ?? 'Scheduled';
            if (!in_array($status, ['Scheduled', 'Completed', 'Cancelled', 'Waiting'])) {
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
        $patientEmails = [];
        foreach ($chunk as $item) {
            $pEmail = $this->getRecordValue($item['data'], ['patientemail', 'patient_email']);
            if ($pEmail) $patientEmails[] = strtolower(trim($pEmail));
        }

        $patients = DB::table('patients')->whereIn('email', $patientEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });

        $insertData = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
            if (!$pEmail) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email',
                    'value' => null,
                    'reason' => 'Patient email is required'
                ];
                $result['failed']++;
                continue;
            }

            $pEmailNorm = strtolower(trim($pEmail));
            $p = $patients->get($pEmailNorm);
            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

            if (!$p) {
                $pPhone = $this->getRecordValue($record, ['patientphone', 'patient_phone', 'phone']);
                $pId = DB::table('patients')->insertGetId([
                    'first_name' => 'Imported',
                    'last_name' => 'Patient',
                    'email' => $pEmailNorm,
                    'phone' => $pPhone,
                    'date_of_birth' => '1990-01-01',
                    'gender' => 'Other',
                    'status' => 'Active',
                    'branch_id' => $branchId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $p = (object)['id' => $pId, 'first_name' => 'Imported', 'last_name' => 'Patient', 'email' => $pEmailNorm];
                $patients->put($pEmailNorm, $p);
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
            $tooth = $this->getRecordValue($record, ['tooth']);

            $insertData[] = [
                'patient_id' => $p->id,
                'procedure' => $procedure,
                'tooth' => $tooth,
                'status' => $status,
                'cost' => (float)$cost,
                'date' => $date,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
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
        $patientEmails = [];
        foreach ($chunk as $item) {
            $pEmail = $this->getRecordValue($item['data'], ['patientemail', 'patient_email']);
            if ($pEmail) $patientEmails[] = strtolower(trim($pEmail));
        }

        $patients = DB::table('patients')->whereIn('email', $patientEmails)->get()->keyBy(function ($item) {
            return strtolower(trim($item->email));
        });

        $insertData = [];

        foreach ($chunk as $item) {
            $rowNumber = $item['rowNumber'];
            $record = $item['data'];

            $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
            if (!$pEmail) {
                $result['errors'][] = [
                    'row' => $rowNumber,
                    'field' => 'patient_email',
                    'value' => null,
                    'reason' => 'Patient email is required'
                ];
                $result['failed']++;
                continue;
            }

            $pEmailNorm = strtolower(trim($pEmail));
            $p = $patients->get($pEmailNorm);
            $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

            if (!$p) {
                $pPhone = $this->getRecordValue($record, ['patientphone', 'patient_phone', 'phone']);
                $pId = DB::table('patients')->insertGetId([
                    'first_name' => 'Imported',
                    'last_name' => 'Patient',
                    'email' => $pEmailNorm,
                    'phone' => $pPhone,
                    'date_of_birth' => '1990-01-01',
                    'gender' => 'Other',
                    'status' => 'Active',
                    'branch_id' => $branchId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $p = (object)['id' => $pId, 'first_name' => 'Imported', 'last_name' => 'Patient', 'email' => $pEmailNorm];
                $patients->put($pEmailNorm, $p);
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

            $insertData[] = [
                'patient_id' => $p->id,
                'patient_name' => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')),
                'amount' => (float)$amount,
                'paid_amount' => $paidAmount,
                'status' => $status,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'branch_id' => $branchId,
                'payment_method' => $paymentMethod,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $result['imported']++;
        }

        if (!empty($insertData)) {
            try {
                DB::table('invoices')->insert($insertData);
            } catch (\Exception $e) {
                Log::error('Financial import error: ' . $e->getMessage());
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
     * 2. Patients (no dependencies)
     * 3. Appointments (references employees & patients)
     * 4. Clinical (references patients)
     * 5. Financial (references patients)
     */
    public function migrateCombined(Request $request)
    {
        set_time_limit(0);
        $startTime = microtime(true);
        $importId = 'imp_' . uniqid();

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx'
        ]);

        $file = $request->file('file');
        $originalExtension = strtolower($file->getClientOriginalExtension());
        $filePath = $file->getPathname();
        $isTempCsv = false;

        // Convert Excel to CSV if needed
        if ($originalExtension === 'xlsx') {
            $tempCsvPath = tempnam(sys_get_temp_dir(), 'xlsx_migrate_') . '.csv';
            $pythonScript = base_path('app/Utils/excel_to_csv.py');
            $command = "python " . escapeshellarg($pythonScript) . " " . escapeshellarg($filePath) . " " . escapeshellarg($tempCsvPath);

            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            if ($resultCode === 0) {
                $filePath = $tempCsvPath;
                $isTempCsv = true;
            } else {
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
                ['row' => 1, 'field' => 'type', 'value' => null, 'reason' => 'CSV must have a "type" column (employee, patient, appointment, clinical, financial)']
            ], $startTime);
        }

        $headers = array_map('trim', $headers);

        // Group data by type while reading
        $groupedData = [
            'employees' => [],
            'patients' => [],
            'appointments' => [],
            'clinical' => [],
            'financial' => []
        ];

        $rowNumber = 2;
        $totalCount = 0;

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
            $type = strtolower(trim($recordData['type'] ?? ''));

            // Validate type
            if (!in_array($type, array_keys($groupedData))) {
                // Skip invalid types instead of failing
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
        $allErrors = [];
        $allWarnings = [];
        $importedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $now = now();

        // Order matters: employees first, then patients, then others
        $processingOrder = ['employees', 'patients', 'appointments', 'clinical', 'financial'];

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
