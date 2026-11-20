<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessImportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @group Data Importing
 * 
 * Endpoints for importing large datasets (patients, employees, treatments) from CSV files
 * with dynamic column mapping and real-time progress tracking.
 */
class ImportController extends Controller
{
    /**
     * Parse File Headers
     * 
     * Upload a CSV file to retrieve its headers and matching database columns.
     * 
     * @authenticated
     * 
     * @bodyParam file file required The CSV file to import.
     * @bodyParam type string required The import target type (patients, employees, treatments). Example: patients
     * 
     * @response 200 {
     *   "file_path": "tenants/{tenant-id}/imports/tempfile.csv",
     *   "headers": ["First Name", "Last Name", "Email Address", "Phone Number"],
     *   "available_columns": ["first_name", "last_name", "email", "phone", "address", "gender", "date_of_birth", "branch_id", "status", "history"]
     * }
     */
    public function parseHeaders(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:204800', // limit to 200MB
            'type' => 'required|string|in:patients,employees,treatments',
        ]);

        $type = $request->input('type');
        $uploadedFile = $request->file('file');
        
        // Get file extension
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'txt', 'xlsx'])) {
            return response()->json(['error' => 'Only CSV, TXT, and XLSX (Excel) files are supported.'], 422);
        }

        $path = $uploadedFile->store(app(\App\Services\TenantContext::class)->storagePath('imports'));
        $fullPath = Storage::path($path);

        // If it is an Excel (xlsx) file, convert it to CSV via Python on the fly
        if ($extension === 'xlsx') {
            $csvPath = app(\App\Services\TenantContext::class)->storagePath('imports/' . Str::uuid() . '.csv');
            $fullCsvPath = Storage::path($csvPath);
            $pythonScript = base_path('app/Utils/excel_to_csv.py');

            // Construct command with escaped arguments for safety
            $command = "python " . escapeshellarg($pythonScript) . " " . escapeshellarg($fullPath) . " " . escapeshellarg($fullCsvPath);
            
            // Execute conversion
            $output = [];
            $resultCode = 0;
            exec($command, $output, $resultCode);

            // Clean up the original xlsx file
            Storage::delete($path);

            if ($resultCode !== 0) {
                return response()->json([
                    'error' => 'Failed to parse Excel file. Make sure it is a valid .xlsx file.'
                ], 400);
            }

            // Update path to point to the newly converted CSV file
            $path = $csvPath;
            $fullPath = $fullCsvPath;
        }

        // Read the first line (headers)
        $file = fopen($fullPath, 'r');
        $headers = fgetcsv($file);
        fclose($file);

        if (!$headers) {
            Storage::delete($path);
            return response()->json(['error' => 'Unable to parse file headers. The file might be empty.'], 400);
        }

        // Clean headers (trim whitespace)
        $headers = array_map('trim', $headers);

        $availableColumns = $this->getAvailableColumns($type);

        return response()->json([
            'file_path' => $path,
            'headers' => $headers,
            'available_columns' => $availableColumns
        ]);
    }

    /**
     * Start Background Import
     * 
     * Dispatch a background job to import the CSV file using the dynamic column mapping.
     * 
     * @authenticated
     * 
     * @bodyParam file_path string required The temporary file path returned by the parse endpoint, including its tenant folder when tenant-scoped. Example: tenants/{tenant-id}/imports/tempfile.csv
     * @bodyParam type string required The import target type. Example: patients
     * @bodyParam mapping object required JSON mapping of database columns to CSV headers. Example: {"first_name": "First Name", "email": "Email Address"}
     * 
     * @response 202 {
     *   "import_id": "9b12c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d",
     *   "status": "queued",
     *   "message": "Import started successfully in background."
     * }
     */
    public function startImport(Request $request)
    {
        $request->validate([
            'file_path' => 'required|string',
            'type' => 'required|string|in:patients,employees,treatments',
            'mapping' => 'required|array',
        ]);

        $filePath = $request->input('file_path');
        $type = $request->input('type');
        $mapping = $request->input('mapping');

        $tenantContext = app(\App\Services\TenantContext::class);
        $normalizedFilePath = str_replace('\\', '/', $filePath);
        $tenantImportDirectory = $tenantContext->storagePath('imports/');

        if (
            $tenantContext->current()
            && (
                !str_starts_with($normalizedFilePath, $tenantImportDirectory)
                || in_array('..', explode('/', $normalizedFilePath), true)
            )
        ) {
            return response()->json(['error' => 'The uploaded file does not exist or has expired.'], 404);
        }

        $fullPath = Storage::path($filePath);

        if (!Storage::exists($filePath)) {
            return response()->json(['error' => 'The uploaded file does not exist or has expired.'], 404);
        }

        // Validate mapping contains at least one required field
        $required = $this->getRequiredColumns($type);
        $mappedKeys = array_keys(array_filter($mapping));
        
        $missing = array_diff($required, $mappedKeys);
        if (!empty($missing)) {
            return response()->json([
                'error' => 'Missing required mappings: ' . implode(', ', $missing)
            ], 422);
        }

        $importId = (string) Str::uuid();

        // Initialize progress status in cache
        Cache::put(app(\App\Services\TenantContext::class)->cacheKey('import_progress_' . $importId), [
            'status' => 'queued',
            'total_rows' => 0,
            'processed_rows' => 0,
            'errors' => []
        ], now()->addHours(2));

        // Dispatch background job
        ProcessImportJob::dispatch($importId, $fullPath, $type, $mapping);

        return response()->json([
            'import_id' => $importId,
            'status' => 'queued',
            'message' => 'Import started successfully in background.'
        ], 202);
    }

    protected function importViaLoadDataLocal($fullPath, $type, $mapping, $headers, $defaultBranchId, $defaultPassword, $now)
    {
        $pdo = DB::connection()->getPdo();
        
        // Ensure local infile is enabled on the PDO connection
        $pdo->setAttribute(\PDO::MYSQL_ATTR_LOCAL_INFILE, true);

        // Map column indices and variables list
        $vars = [];
        for ($i = 0; $i < count($headers); $i++) {
            $vars[] = '@var' . $i;
        }
        $varsList = implode(', ', $vars);

        $tableName = $type;
        $setClauses = [];
        $headerIndices = array_flip($headers);
        $nowString = $now->toDateTimeString();

        if ($type === 'patients') {
            $cols = ['first_name', 'last_name', 'email', 'phone', 'address', 'gender', 'date_of_birth', 'branch_id', 'status', 'history'];
            foreach ($cols as $col) {
                if (isset($mapping[$col]) && isset($headerIndices[$mapping[$col]])) {
                    $idx = $headerIndices[$mapping[$col]];
                    $setClauses[] = "$col = NULLIF(TRIM(@var$idx), '')";
                }
            }
            
            if (!isset($mapping['first_name'])) $setClauses[] = "first_name = 'Imported'";
            if (!isset($mapping['last_name'])) $setClauses[] = "last_name = 'Patient'";
            if (!isset($mapping['gender'])) $setClauses[] = "gender = 'Other'";
            if (!isset($mapping['date_of_birth'])) $setClauses[] = "date_of_birth = '1990-01-01'";
            if (!isset($mapping['branch_id'])) $setClauses[] = "branch_id = $defaultBranchId";
            if (!isset($mapping['status'])) $setClauses[] = "status = 'Active'";

            $setClauses[] = "created_at = '$nowString'";
            $setClauses[] = "updated_at = '$nowString'";
            $keyword = "REPLACE";

        } elseif ($type === 'employees') {
            $cols = ['name', 'avatar', 'email', 'role', 'role_id', 'salary', 'hire_date', 'branch_id', 'status'];
            foreach ($cols as $col) {
                if (isset($mapping[$col]) && isset($headerIndices[$mapping[$col]])) {
                    $idx = $headerIndices[$mapping[$col]];
                    $setClauses[] = "$col = NULLIF(TRIM(@var$idx), '')";
                }
            }

            if (!isset($mapping['name'])) $setClauses[] = "name = 'Imported Employee'";
            if (!isset($mapping['role_id'])) $setClauses[] = "role_id = 4";
            if (!isset($mapping['role'])) {
                $setClauses[] = "role = COALESCE((SELECT name FROM role_permissions WHERE id = role_id LIMIT 1), 'Dentist')";
            }
            if (!isset($mapping['salary'])) $setClauses[] = "salary = 0";
            if (!isset($mapping['hire_date'])) $setClauses[] = "hire_date = '" . $now->format('Y-m-d') . "'";
            if (!isset($mapping['branch_id'])) $setClauses[] = "branch_id = $defaultBranchId";
            if (!isset($mapping['status'])) $setClauses[] = "status = 'Active'";
            
            $setClauses[] = "password = '$defaultPassword'";
            $setClauses[] = "created_at = '$nowString'";
            $setClauses[] = "updated_at = '$nowString'";
            $keyword = "REPLACE";

        } elseif ($type === 'treatments') {
            $cols = ['procedure', 'cost', 'category'];
            foreach ($cols as $col) {
                if (isset($mapping[$col]) && isset($headerIndices[$mapping[$col]])) {
                    $idx = $headerIndices[$mapping[$col]];
                    $setClauses[] = "$col = NULLIF(TRIM(@var$idx), '')";
                }
            }
            if (!isset($mapping['cost'])) $setClauses[] = "cost = 0";
            $setClauses[] = "created_at = '$nowString'";
            $setClauses[] = "updated_at = '$nowString'";
            $keyword = "REPLACE";
        }

        $setClauseStr = implode(', ', $setClauses);
        $escapedPath = str_replace('\\', '\\\\', $fullPath);

        // Support both Windows and Unix line endings dynamically
        $sql = "LOAD DATA LOCAL INFILE '$escapedPath'
                $keyword INTO TABLE $tableName
                CHARACTER SET utf8mb4
                FIELDS TERMINATED BY ','
                ENCLOSED BY '\"'
                LINES TERMINATED BY '\n'
                IGNORE 1 LINES
                ($varsList)
                SET $setClauseStr";

        $affectedRows = $pdo->exec($sql);
        
        // Return row count (estimate total lines by subtracting 1 header)
        $lineCount = 0;
        $handle = fopen($fullPath, 'r');
        while (!feof($handle)) {
            fgets($handle);
            $lineCount++;
        }
        fclose($handle);
        
        return max(0, $lineCount - 2);
    }


    /**
     * Get Import Progress
     * 
     * Retrieve the real-time status and count of processed records for an import job.
     * 
     * @authenticated
     * 
     * @urlParam id string required The unique import ID. Example: 9b12c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d
     * 
     * @response 200 {
     *   "status": "processing",
     *   "total_rows": 100000,
     *   "processed_rows": 45200,
     *   "errors": []
     * }
     */
    public function getProgress($id)
    {
        $progress = Cache::get(app(\App\Services\TenantContext::class)->cacheKey('import_progress_' . $id));

        if (!$progress) {
            return response()->json(['error' => 'Import job not found or progress expired.'], 404);
        }

        return response()->json($progress);
    }

    /**
     * List Available Templates
     * 
     * Get a list of all downloadable CSV and Excel templates for patients, employees, and treatments.
     * 
     * @authenticated
     * 
     * @response 200 [
     *   {
     *     "name": "patients_sample.csv",
     *     "type": "patients",
     *     "format": "csv",
     *     "download_url": "http://chart-backend.test/api/import/templates/download/patients_sample.csv"
     *   }
     * ]
     */
    public function listTemplates()
    {
        $files = [
            ['name' => 'patients_sample.csv', 'type' => 'patients', 'format' => 'csv'],
            ['name' => 'patients_sample.xlsx', 'type' => 'patients', 'format' => 'xlsx'],
            ['name' => 'employees_sample.csv', 'type' => 'employees', 'format' => 'csv'],
            ['name' => 'employees_sample.xlsx', 'type' => 'employees', 'format' => 'xlsx'],
            ['name' => 'treatments_sample.csv', 'type' => 'treatments', 'format' => 'csv'],
            ['name' => 'treatments_sample.xlsx', 'type' => 'treatments', 'format' => 'xlsx'],
            ['name' => 'appointments_sample.csv', 'type' => 'appointments', 'format' => 'csv'],
            ['name' => 'appointments_sample.xlsx', 'type' => 'appointments', 'format' => 'xlsx'],
            ['name' => 'invoices_sample.csv', 'type' => 'invoices', 'format' => 'csv'],
            ['name' => 'invoices_sample.xlsx', 'type' => 'invoices', 'format' => 'xlsx'],
            ['name' => 'financial_sample.csv', 'type' => 'financial', 'format' => 'csv'],
            ['name' => 'financial_sample.xlsx', 'type' => 'financial', 'format' => 'xlsx'],
            ['name' => 'inventory_sample.csv', 'type' => 'inventory', 'format' => 'csv'],
            ['name' => 'inventory_sample.xlsx', 'type' => 'inventory', 'format' => 'xlsx'],
            ['name' => 'medicines_sample.csv', 'type' => 'medicines', 'format' => 'csv'],
            ['name' => 'medicines_sample.xlsx', 'type' => 'medicines', 'format' => 'xlsx'],
            ['name' => 'medical_history_sample.csv', 'type' => 'medical_history', 'format' => 'csv'],
            ['name' => 'medical_history_sample.xlsx', 'type' => 'medical_history', 'format' => 'xlsx'],
            ['name' => 'lab_orders_sample.csv', 'type' => 'lab_orders', 'format' => 'csv'],
            ['name' => 'lab_orders_sample.xlsx', 'type' => 'lab_orders', 'format' => 'xlsx'],
        ];

        $templates = array_map(function ($file) {
            return [
                'name' => $file['name'],
                'type' => $file['type'],
                'format' => $file['format'],
                'download_url' => url('/api/import/templates/download/' . $file['name'])
            ];
        }, $files);

        return response()->json($templates);
    }

    /**
     * Download Template File
     * 
     * Download a specific CSV or Excel template by filename.
     * 
     * @authenticated
     * 
     * @urlParam filename string required The filename of the template. Example: patients_sample.csv
     */
    public function downloadTemplate($filename)
    {
        $filename = basename($filename);
        $path = public_path('samples/' . $filename);

        if (!file_exists($path)) {
            return response()->json(['error' => 'Template not found.'], 404);
        }

        return response()->download($path);
    }

    protected function getAvailableColumns($type)
    {
        switch ($type) {
            Case 'patients':
                Return ['first_name', 'last_name', 'email', 'phone', 'address', 'gender', 'date_of_birth', 'branch_id', 'status', 'history'];
            Case 'employees':
                Return ['name', 'avatar', 'email', 'role', 'role_id', 'salary', 'hire_date', 'branch_id', 'status'];
            Case 'treatments':
                Return ['procedure', 'cost', 'category'];
            Default:
                Return [];
        }
    }

    protected function getRequiredColumns($type)
    {
        switch ($type) {
            case 'patients':
                return ['email']; // first_name/last_name have fallbacks
            case 'employees':
                return ['email']; // name has fallback
            case 'treatments':
                return ['procedure'];
            default:
                return [];
        }
    }

    protected function processSynchronousChunk($chunk, $type, $defaultBranchId, $defaultPassword, $now)
    {
        if (empty($chunk)) return;

        if ($type === 'patients') {
            $data = [];
            foreach ($chunk as $row) {
                if (empty($row['email'])) continue;
                $data[] = [
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
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($data)) {
                DB::table('patients')->upsert(
                    $data, 
                    ['email'], 
                    ['first_name', 'last_name', 'phone', 'address', 'gender', 'date_of_birth', 'branch_id', 'status', 'history', 'updated_at']
                );
            }
        } elseif ($type === 'employees') {
            $data = [];
            $rolesCache = [];

            foreach ($chunk as $row) {
                if (empty($row['email'])) continue;
                
                $roleId = $row['role_id'] ?? 4;
                $roleName = $row['role'] ?? null;
                if (!$roleName) {
                    if (!isset($rolesCache[$roleId])) {
                        $rolesCache[$roleId] = DB::table('role_permissions')->where('id', $roleId)->value('name') ?? 'Dentist';
                    }
                    $roleName = $rolesCache[$roleId];
                }

                $data[] = [
                    'name' => $row['name'] ?? 'Imported Employee',
                    'avatar' => $row['avatar'] ?? null,
                    'email' => $row['email'],
                    'role' => $roleName,
                    'role_id' => $roleId,
                    'salary' => $row['salary'] ?? 0,
                    'hire_date' => $row['hire_date'] ?? $now->format('Y-m-d'),
                    'branch_id' => $row['branch_id'] ?? $defaultBranchId,
                    'status' => in_array($row['status'] ?? '', ['Active', 'On Leave']) ? $row['status'] : 'Active',
                    'password' => $defaultPassword,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($data)) {
                DB::table('employees')->upsert(
                    $data, 
                    ['email'], 
                    ['name', 'avatar', 'role', 'role_id', 'salary', 'hire_date', 'branch_id', 'status', 'updated_at']
                );
            }
        } elseif ($type === 'treatments') {
            $data = [];
            foreach ($chunk as $row) {
                if (empty($row['procedure'])) continue;
                $data[] = [
                    'procedure' => $row['procedure'],
                    'cost' => $row['cost'] ?? 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($data)) {
                $procedures = collect($data)->pluck('procedure')->toArray();
                $existing = DB::table('treatments')->whereIn('procedure', $procedures)->pluck('id', 'procedure')->toArray();

                $toInsert = [];
                foreach ($data as $row) {
                    if (isset($existing[$row['procedure']])) {
                        DB::table('treatments')->where('id', $existing[$row['procedure']])->update([
                            'cost' => $row['cost'],
                            'updated_at' => $row['updated_at']
                        ]);
                    } else {
                        $toInsert[] = $row;
                    }
                }
                if (!empty($toInsert)) {
                    DB::table('treatments')->insert($toInsert);
                }
            }
        }
    }
}
