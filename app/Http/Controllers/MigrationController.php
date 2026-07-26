<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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

        $handle = fopen($file->getPathname(), "r");
        $headers = fgetcsv($handle); // Read headers
        
        $chunk = [];
        $chunkSize = 1000;
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($headers) !== count($row)) continue;
            
            $chunk[] = array_combine($headers, $row);

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
            foreach ($chunk as $record) {
                $upsertData[] = [
                    'email' => $record['email'],
                    'first_name' => $record['first_name'] ?? 'Unknown',
                    'last_name' => $record['last_name'] ?? '',
                    'phone' => $record['phone'] ?? null,
                    'role' => $record['role'] ?? 'Staff',
                    'status' => $record['status'] ?? 'Active',
                    'branch_id' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            \App\Models\Employee::upsert($upsertData, ['email'], ['first_name', 'last_name', 'phone', 'role', 'status', 'updated_at']);
            
        } elseif ($section === 'patients') {
            $upsertData = [];
            foreach ($chunk as $record) {
                $upsertData[] = [
                    'email' => $record['email'],
                    'first_name' => $record['first_name'] ?? 'Unknown',
                    'last_name' => $record['last_name'] ?? '',
                    'phone' => $record['phone'] ?? null,
                    'address' => $record['address'] ?? null,
                    'gender' => $record['gender'] ?? 'Other',
                    'date_of_birth' => $record['date_of_birth'] ?? null,
                    'status' => 'Active',
                    'branch_id' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            \App\Models\Patient::upsert($upsertData, ['email'], ['first_name', 'last_name', 'phone', 'address', 'gender', 'date_of_birth', 'updated_at']);

        } elseif ($section === 'appointments') {
            $patientEmails = array_column($chunk, 'patient_email');
            $doctorEmails = array_column($chunk, 'doctor_email');
            
            $patients = DB::table('patients')->whereIn('email', $patientEmails)->get()->keyBy('email');
            $doctors = DB::table('employees')->whereIn('email', $doctorEmails)->get()->keyBy('email');
                
            $insertData = [];
            foreach ($chunk as $record) {
                $p = $patients->get($record['patient_email']);
                $d = $doctors->get($record['doctor_email']);
                
                if ($p && $d) {
                    $insertData[] = [
                        'patient_id' => $p->id,
                        'patient_name' => $p->first_name . ' ' . $p->last_name,
                        'doctor_id' => $d->id,
                        'doctor_name' => $d->first_name . ' ' . $d->last_name,
                        'date' => $record['appointment_date'] ?? $now,
                        'treatment' => $record['reason'] ?? 'General Checkup',
                        'status' => $record['status'] ?? 'Scheduled',
                        'branch_id' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if (!empty($insertData)) DB::table('appointments')->insert($insertData);

        } elseif ($section === 'financial') {
            $patientEmails = array_column($chunk, 'patient_email');
            $patients = DB::table('patients')->whereIn('email', $patientEmails)->get()->keyBy('email');
                
            $insertData = [];
            foreach ($chunk as $record) {
                $p = $patients->get($record['patient_email']);
                if ($p) {
                    $insertData[] = [
                        'patient_id' => $p->id,
                        'invoice_number' => $record['invoice_number'] ?? 'INV-' . rand(1000, 99999),
                        'amount' => $record['amount'] ?? 0,
                        'status' => $record['status'] ?? 'Unpaid',
                        'branch_id' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if (!empty($insertData)) DB::table('invoices')->insert($insertData);

        } elseif ($section === 'clinical') {
            $patientEmails = array_column($chunk, 'patient_email');
            $patients = DB::table('patients')->whereIn('email', $patientEmails)->get()->keyBy('email');
                
            $insertData = [];
            foreach ($chunk as $record) {
                $p = $patients->get($record['patient_email']);
                if ($p) {
                    $insertData[] = [
                        'patient_id' => $p->id,
                        'treatment_name' => $record['treatment_name'] ?? 'General',
                        'status' => $record['status'] ?? 'Planned',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if (!empty($insertData)) DB::table('treatment_plans')->insert($insertData);
        }
    }
}
