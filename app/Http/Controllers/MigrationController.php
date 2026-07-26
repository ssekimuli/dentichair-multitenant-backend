<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MigrationController extends Controller
{
    public function migrate(Request $request)
    {
        // Prevent PHP from timing out for large files
        set_time_limit(0);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx',
            'section' => 'required|string|in:employees,patients,appointments,clinical,financial'
        ]);

        $section = $request->input('section');
        $file = $request->file('file');
        
        $originalExtension = strtolower($file->getClientOriginalExtension());
        $filePath = $file->getPathname();
        $isTempCsv = false;

        // If it is an Excel (xlsx) file, convert it to CSV via Python on the fly
        if ($originalExtension === 'xlsx') {
            $tempCsvPath = tempnam(sys_get_temp_dir(), 'xlsx_migrate_') . '.csv';
            $pythonScript = base_path('app/Utils/excel_to_csv.py');

            // Construct command with escaped arguments for safety
            $command = "python " . escapeshellarg($pythonScript) . " " . escapeshellarg($filePath) . " " . escapeshellarg($tempCsvPath);
            
            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            if ($resultCode === 0) {
                $filePath = $tempCsvPath;
                $isTempCsv = true;
            } else {
                return response()->json([
                    'error' => 'Failed to parse Excel file.'
                ], 400);
            }
        }

        $handle = fopen($filePath, "r");
        $headers = fgetcsv($handle); // Read headers
        
        if (!$headers) {
            fclose($handle);
            if ($isTempCsv && file_exists($filePath)) {
                unlink($filePath);
            }
            return response()->json([
                'error' => 'File is empty or invalid.'
            ], 400);
        }

        // Normalize headers
        $headers = array_map('trim', $headers);
        
        $chunk = [];
        $chunkSize = 1000;
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            // Skip empty rows
            if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                continue;
            }

            // Pad row if it has fewer elements than headers
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }
            
            $chunk[] = array_combine($headers, array_slice($row, 0, count($headers)));

            if (count($chunk) >= $chunkSize) {
                $this->processChunk($section, $chunk);
                $count += count($chunk);
                $chunk = [];
            }
        }

        // Process remaining rows
        if (count($chunk) > 0) {
            $this->processChunk($section, $chunk);
            $count += count($chunk);
        }

        fclose($handle);

        if ($isTempCsv && file_exists($filePath)) {
            unlink($filePath);
        }
        
        return response()->json([
            'message' => "Successfully imported data for {$section}",
            'count' => $count
        ]);
    }

    private function processChunk($section, $chunk)
    {
        $now = now();

        if ($section === 'employees') {
            $upsertData = [];
            $rolesCache = [];
            foreach ($chunk as $record) {
                $email = $this->getRecordValue($record, ['email', 'emailaddress', 'email_address']);
                if (empty($email)) {
                    continue;
                }

                $name = $this->getRecordValue($record, ['name', 'fullname', 'full_name']);
                if (empty($name)) {
                    $firstName = $this->getRecordValue($record, ['firstname', 'first_name']);
                    $lastName = $this->getRecordValue($record, ['lastname', 'last_name']);
                    $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
                }
                if (empty($name)) {
                    $name = 'Imported Employee';
                }

                $roleId = $this->getRecordValue($record, ['roleid', 'role_id']);
                $roleName = $this->getRecordValue($record, ['role', 'rolename', 'role_name']);

                if (empty($roleId) && !empty($roleName)) {
                    $roleId = DB::table('role_permissions')->where('name', $roleName)->value('id') ?? 4;
                } elseif (empty($roleId)) {
                    $roleId = 4; // Dentist
                }

                if (empty($roleName)) {
                    if (!isset($rolesCache[$roleId])) {
                        $rolesCache[$roleId] = DB::table('role_permissions')->where('id', $roleId)->value('name') ?? 'Dentist';
                    }
                    $roleName = $rolesCache[$roleId];
                }

                $salary = $this->getRecordValue($record, ['salary', 'monthlysalary', 'monthly_salary']) ?? 0;
                $hireDate = $this->getRecordValue($record, ['hiredate', 'hire_date']) ?? $now->format('Y-m-d');
                $status = $this->getRecordValue($record, ['status']) ?? 'Active';
                if (!in_array($status, ['Active', 'On Leave'])) {
                    $status = 'Active';
                }
                $branchId = $this->getRecordValue($record, ['branchid', 'branch_id']) ?? 1;

                $upsertData[] = [
                    'name' => $name,
                    'email' => $email,
                    'role' => $roleName,
                    'role_id' => $roleId,
                    'salary' => $salary,
                    'hire_date' => $hireDate,
                    'status' => $status,
                    'branch_id' => $branchId,
                    'password' => Hash::make('password'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($upsertData)) {
                DB::table('employees')->upsert(
                    $upsertData,
                    ['email'],
                    ['name', 'role', 'role_id', 'salary', 'hire_date', 'status', 'branch_id', 'updated_at']
                );
            }
            
        } elseif ($section === 'patients') {
            $upsertData = [];
            foreach ($chunk as $record) {
                $email = $this->getRecordValue($record, ['email', 'emailaddress', 'email_address']);
                if (empty($email)) {
                    continue;
                }

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
                if (empty($firstName)) {
                    $firstName = 'Unknown';
                }
                if ($lastName === null) {
                    $lastName = '';
                }

                $phone = $this->getRecordValue($record, ['phone', 'phonenumber', 'phone_number']);
                $address = $this->getRecordValue($record, ['address']);
                
                $gender = $this->getRecordValue($record, ['gender']);
                if (!empty($gender)) {
                    $gender = ucfirst(strtolower(trim($gender)));
                    if (!in_array($gender, ['Male', 'Female', 'Other'])) {
                        $gender = 'Other';
                    }
                } else {
                    $gender = 'Other';
                }

                $dob = $this->getRecordValue($record, ['dateofbirth', 'date_of_birth', 'birthdate', 'birth_date', 'dob']) ?? '1990-01-01';
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
                    'branch_id' => $branchId,
                    'history' => $history,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($upsertData)) {
                DB::table('patients')->upsert(
                    $upsertData,
                    ['email'],
                    ['first_name', 'last_name', 'phone', 'address', 'gender', 'date_of_birth', 'status', 'branch_id', 'history', 'updated_at']
                );
            }

        } elseif ($section === 'appointments') {
            $patientEmails = [];
            $doctorEmails = [];
            foreach ($chunk as $record) {
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
            foreach ($chunk as $record) {
                $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
                $dEmail = $this->getRecordValue($record, ['doctoremail', 'doctor_email']);
                
                if (!$pEmail || !$dEmail) {
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
                    $p = (object)[
                        'id' => $pId,
                        'first_name' => 'Imported',
                        'last_name' => 'Patient',
                        'email' => $pEmailNorm
                    ];
                    $patients->put($pEmailNorm, $p);
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
                    $d = (object)[
                        'id' => $dId,
                        'name' => 'Imported Doctor',
                        'email' => $dEmailNorm
                    ];
                    $doctors->put($dEmailNorm, $d);
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
            }
            if (!empty($insertData)) {
                DB::table('appointments')->insert($insertData);
            }

        } elseif ($section === 'financial') {
            $patientEmails = [];
            foreach ($chunk as $record) {
                $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
                if ($pEmail) $patientEmails[] = strtolower(trim($pEmail));
            }

            $patients = DB::table('patients')->whereIn('email', $patientEmails)->get()->keyBy(function ($item) {
                return strtolower(trim($item->email));
            });

            $insertData = [];
            foreach ($chunk as $record) {
                $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
                if (!$pEmail) continue;
                
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
                    $p = (object)[
                        'id' => $pId,
                        'first_name' => 'Imported',
                        'last_name' => 'Patient',
                        'email' => $pEmailNorm
                    ];
                    $patients->put($pEmailNorm, $p);
                }

                $amount = $this->getRecordValue($record, ['amount']) ?? 0;
                $status = $this->getRecordValue($record, ['status']) ?? 'Pending';
                
                if (strcasecmp($status, 'Paid') === 0) {
                    $status = 'Paid';
                } elseif (strcasecmp($status, 'Overdue') === 0) {
                    $status = 'Overdue';
                } else {
                    $status = 'Pending';
                }

                $paidAmount = ($status === 'Paid') ? $amount : 0;

                $rawDate = $this->getRecordValue($record, ['date', 'issuedate', 'issue_date']);
                $issueDate = $rawDate ? date('Y-m-d', strtotime($rawDate)) : $now->format('Y-m-d');
                
                $rawDueDate = $this->getRecordValue($record, ['duedate', 'due_date']);
                $dueDate = $rawDueDate ? date('Y-m-d', strtotime($rawDueDate)) : date('Y-m-d', strtotime($issueDate . ' +14 days'));

                $paymentMethod = ($status === 'Paid') ? 'Cash' : null;

                $insertData[] = [
                    'patient_id' => $p->id,
                    'patient_name' => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')),
                    'amount' => $amount,
                    'paid_amount' => $paidAmount,
                    'status' => $status,
                    'issue_date' => $issueDate,
                    'due_date' => $dueDate,
                    'branch_id' => $branchId,
                    'payment_method' => $paymentMethod,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($insertData)) {
                DB::table('invoices')->insert($insertData);
            }

        } elseif ($section === 'clinical') {
            $patientEmails = [];
            foreach ($chunk as $record) {
                $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
                if ($pEmail) $patientEmails[] = strtolower(trim($pEmail));
            }

            $patients = DB::table('patients')->whereIn('email', $patientEmails)->get()->keyBy(function ($item) {
                return strtolower(trim($item->email));
            });

            $insertData = [];
            foreach ($chunk as $record) {
                $pEmail = $this->getRecordValue($record, ['patientemail', 'patient_email']);
                if (!$pEmail) continue;

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
                    $p = (object)[
                        'id' => $pId,
                        'first_name' => 'Imported',
                        'last_name' => 'Patient',
                        'email' => $pEmailNorm
                    ];
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
                $rawDate = $this->getRecordValue($record, ['date', 'createdat', 'created_at']);
                $date = $rawDate ? date('Y-m-d', strtotime($rawDate)) : $now->format('Y-m-d');
                
                $tooth = $this->getRecordValue($record, ['tooth']);

                $insertData[] = [
                    'patient_id' => $p->id,
                    'procedure' => $procedure,
                    'tooth' => $tooth,
                    'status' => $status,
                    'cost' => $cost,
                    'date' => $date,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($insertData)) {
                DB::table('treatment_plans')->insert($insertData);
            }
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
}

