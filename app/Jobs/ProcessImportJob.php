<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\RolePermission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout for 100k records

    protected $importId;
    protected $filePath;
    protected $type;
    protected $mapping;

    public function __construct($importId, $filePath, $type, $mapping)
    {
        $this->importId = $importId;
        $this->filePath = $filePath;
        $this->type = $type;
        $this->mapping = $mapping;
    }

    public function handle()
    {
        $cacheKey = app(\App\Services\TenantContext::class)->cacheKey('import_progress_' . $this->importId);
        
        try {
            if (!file_exists($this->filePath)) {
                throw new \Exception("Import file not found.");
            }

            // 1. Count total rows
            $totalRows = 0;
            $file = fopen($this->filePath, 'r');
            while (!feof($file)) {
                fgets($file);
                $totalRows++;
            }
            fclose($file);
            $totalRows = max(0, $totalRows - 2); // Subtract header and possible empty trailing line

            Cache::put($cacheKey, [
                'status' => 'processing',
                'total_rows' => $totalRows,
                'processed_rows' => 0,
                'errors' => []
            ], now()->addHours(2));

            // 2. Process file in chunks
            $file = fopen($this->filePath, 'r');
            $headers = fgetcsv($file); // read header row

            if (!$headers) {
                throw new \Exception("CSV file is empty or invalid.");
            }

            // Normalize headers
            $headers = array_map('trim', $headers);

            $defaultBranchId = Branch::first()?->id ?? 1;
            $chunkSize = 1000;
            $chunk = [];
            $processedRows = 0;
            $errors = [];

            while (($row = fgetcsv($file)) !== false) {
                // Handle empty rows
                if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                    continue;
                }

                // Pad row if it has fewer elements than headers
                if (count($row) < count($headers)) {
                    $row = array_pad($row, count($headers), '');
                }

                $rowData = array_combine($headers, array_slice($row, 0, count($headers)));
                
                // Map the row using dynamic mapping
                $mappedRow = [];
                foreach ($this->mapping as $dbColumn => $csvHeader) {
                    $mappedRow[$dbColumn] = isset($rowData[$csvHeader]) ? trim($rowData[$csvHeader]) : null;
                }

                $chunk[] = $mappedRow;

                if (count($chunk) >= $chunkSize) {
                    $this->processChunk($chunk, $defaultBranchId);
                    $processedRows += count($chunk);
                    
                    Cache::put($cacheKey, [
                        'status' => 'processing',
                        'total_rows' => $totalRows,
                        'processed_rows' => $processedRows,
                        'errors' => $errors
                    ], now()->addHours(2));

                    $chunk = [];
                }
            }

            if (!empty($chunk)) {
                $this->processChunk($chunk, $defaultBranchId);
                $processedRows += count($chunk);
            }

            fclose($file);

            // Clean up temporary file
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }

            // Complete progress
            Cache::put($cacheKey, [
                'status' => 'completed',
                'total_rows' => $totalRows,
                'processed_rows' => $processedRows,
                'errors' => $errors
            ], now()->addHours(2));

        } catch (\Exception $e) {
            Log::error("Import error: " . $e->getMessage());
            Cache::put($cacheKey, [
                'status' => 'failed',
                'total_rows' => $totalRows ?? 0,
                'processed_rows' => $processedRows ?? 0,
                'errors' => [$e->getMessage()]
            ], now()->addHours(2));

            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }
        }
    }

    protected function processChunk($chunk, $defaultBranchId)
    {
        if ($this->type === 'patients') {
            $this->processPatients($chunk, $defaultBranchId);
        } elseif ($this->type === 'employees') {
            $this->processEmployees($chunk, $defaultBranchId);
        } elseif ($this->type === 'treatments') {
            $this->processTreatments($chunk);
        }
    }

    protected function processPatients($chunk, $defaultBranchId)
    {
        $emails = collect($chunk)->pluck('email')->filter()->toArray();
        $existing = Patient::whereIn('email', $emails)->pluck('id', 'email')->toArray();

        $toInsert = [];
        $now = now();

        foreach ($chunk as $row) {
            if (empty($row['email'])) {
                continue;
            }

            $data = [
                'first_name' => $row['first_name'] ?? 'Imported',
                'last_name' => $row['last_name'] ?? 'Patient',
                'email' => $row['email'],
                'phone' => $row['phone'] ?? null,
                'address' => $row['address'] ?? null,
                'gender' => in_array($row['gender'] ?? '', ['Male', 'Female', 'Other']) ? $row['gender'] : 'Other',
                'date_of_birth' => $row['date_of_birth'] ?? '1990-01-01',
                'branch_id' => $row['branch_id'] ?? $defaultBranchId,
                'status' => in_array($row['status'] ?? '', ['Active', 'Inactive']) ? $row['status'] : 'Active',
                'history' => $row['history'] ?? null,
                'updated_at' => $now,
            ];

            if (isset($existing[$row['email']])) {
                Patient::where('id', $existing[$row['email']])->update($data);
            } else {
                $data['created_at'] = $now;
                $toInsert[] = $data;
            }
        }

        if (!empty($toInsert)) {
            Patient::insert($toInsert);
        }
    }

    protected function processEmployees($chunk, $defaultBranchId)
    {
        $emails = collect($chunk)->pluck('email')->filter()->toArray();
        $existing = Employee::whereIn('email', $emails)->pluck('id', 'email')->toArray();

        $toInsert = [];
        $now = now();
        $defaultPassword = Hash::make('password');

        foreach ($chunk as $row) {
            if (empty($row['email'])) {
                continue;
            }

            $roleId = $row['role_id'] ?? 4;
            $roleName = $row['role'] ?? null;
            if (!$roleName) {
                $roleName = RolePermission::find($roleId)?->name ?? 'Dentist';
            }

            $data = [
                'name' => $row['name'] ?? 'Imported Employee',
                'avatar' => $row['avatar'] ?? null,
                'email' => $row['email'],
                'role' => $roleName,
                'role_id' => $roleId,
                'salary' => $row['salary'] ?? 0,
                'role_id' => $roleId,
                'hire_date' => $row['hire_date'] ?? $now->format('Y-m-d'),
                'branch_id' => $row['branch_id'] ?? $defaultBranchId,
                'status' => in_array($row['status'] ?? '', ['Active', 'On Leave']) ? $row['status'] : 'Active',
                'updated_at' => $now,
            ];

            if (isset($existing[$row['email']])) {
                Employee::where('id', $existing[$row['email']])->update($data);
            } else {
                $data['password'] = $defaultPassword;
                $data['created_at'] = $now;
                $toInsert[] = $data;
            }
        }

        if (!empty($toInsert)) {
            Employee::insert($toInsert);
        }
    }

    protected function processTreatments($chunk)
    {
        $procedures = collect($chunk)->pluck('procedure')->filter()->toArray();
        $existing = Treatment::whereIn('procedure', $procedures)->pluck('id', 'procedure')->toArray();

        $toInsert = [];
        $now = now();

        foreach ($chunk as $row) {
            if (empty($row['procedure'])) {
                continue;
            }

            $data = [
                'procedure' => $row['procedure'],
                'cost' => $row['cost'] ?? 0,
                'updated_at' => $now,
            ];

            if (isset($existing[$row['procedure']])) {
                Treatment::where('id', $existing[$row['procedure']])->update($data);
            } else {
                $data['created_at'] = $now;
                $toInsert[] = $data;
            }
        }

        if (!empty($toInsert)) {
            Treatment::insert($toInsert);
        }
    }
}
