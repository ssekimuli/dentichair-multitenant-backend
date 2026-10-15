<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\LaboratoryOrder;
use App\Models\MedicalHistory;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Treatment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Data Exporting
 * 
 * APIs to export data (Patients, Employees, Treatments, Invoices, Inventory, Medicines, Appointments, Expenses, or Full Database)
 * into CSV, Excel (.xlsx), or JSON.
 */
class ExportController extends Controller
{
    /**
     * Export Entity Data
     * 
     * Stream or download an export of a selected entity in CSV or Excel (XLSX) format.
     * 
     * @authenticated
     * 
     * @queryParam type string required The entity to export: patients, employees, treatments, invoices, inventory, medicines, appointments, expenses. Example: patients
     * @queryParam format string The format of the file: csv or xlsx. Default is csv. Example: xlsx
     * @queryParam branch_id int Optional branch filter. Example: 1
     * @queryParam status string Optional status filter. Example: Active
     * @queryParam start_date string Optional start date for date-based records (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam end_date string Optional end date for date-based records (YYYY-MM-DD). Example: 2026-12-31
     */
    public function export(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:patients,employees,treatments,invoices,inventory,medicines,appointments,expenses,medical_history,lab_orders',
            'format' => 'nullable|string|in:csv,xlsx',
            'branch_id' => 'nullable|integer',
            'status' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $type = $request->input('type');
        $format = strtolower($request->input('format', 'csv'));

        if ($format === 'xlsx') {
            return $this->exportExcel($request, $type);
        }

        return $this->exportCsv($request, $type);
    }

    /**
     * Stream CSV Export
     */
    protected function exportCsv(Request $request, string $type): StreamedResponse
    {
        $filename = "{$type}_export_" . date('Y-m-d_His') . ".csv";

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->streamDownload(function () use ($request, $type) {
            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM so Excel opens CSV with proper encoding
            fputs($handle, "\xEF\xBB\xBF");

            $columns = $this->getExportColumns($type);
            fputcsv($handle, array_keys($columns));

            $query = $this->buildQuery($request, $type);

            $query->chunk(500, function ($rows) use ($handle, $columns) {
                foreach ($rows as $row) {
                    $csvRow = [];
                    foreach ($columns as $header => $field) {
                        $val = is_callable($field) ? $field($row) : data_get($row, $field);
                        if (is_array($val) || is_object($val)) {
                            $val = json_encode($val);
                        }
                        $csvRow[] = $val ?? '';
                    }
                    fputcsv($handle, $csvRow);
                }
            });

            fclose($handle);
        }, $filename, $headers);
    }

    /**
     * Export XLSX using python openpyxl converter
     */
    protected function exportExcel(Request $request, string $type)
    {
        $tempCsvName = 'exports/temp_' . Str::uuid() . '.csv';
        $tempXlsxName = 'exports/' . "{$type}_export_" . date('Y-m-d_His') . '.xlsx';

        $fullCsvPath = Storage::disk('local')->path($tempCsvName);
        $fullXlsxPath = Storage::disk('local')->path($tempXlsxName);

        // Ensure exports directory exists
        if (!Storage::disk('local')->exists('exports')) {
            Storage::disk('local')->makeDirectory('exports');
        }

        $handle = fopen($fullCsvPath, 'w');
        fputs($handle, "\xEF\xBB\xBF");

        $columns = $this->getExportColumns($type);
        fputcsv($handle, array_keys($columns));

        $query = $this->buildQuery($request, $type);

        $query->chunk(500, function ($rows) use ($handle, $columns) {
            foreach ($rows as $row) {
                $csvRow = [];
                foreach ($columns as $header => $field) {
                    $val = is_callable($field) ? $field($row) : data_get($row, $field);
                    if (is_array($val) || is_object($val)) {
                        $val = json_encode($val);
                    }
                    $csvRow[] = $val ?? '';
                }
                fputcsv($handle, $csvRow);
            }
        });
        fclose($handle);

        // Run python converter
        $scriptPath = base_path('app/Utils/csv_to_excel.py');
        $sheetTitle = ucfirst($type);
        $cmd = "python " . escapeshellarg($scriptPath) . " " . escapeshellarg($fullCsvPath) . " " . escapeshellarg($fullXlsxPath) . " " . escapeshellarg($sheetTitle);

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        // Delete temporary CSV
        if (file_exists($fullCsvPath)) {
            @unlink($fullCsvPath);
        }

        if ($exitCode !== 0 || !file_exists($fullXlsxPath)) {
            // Fallback to CSV stream if excel generation failed
            return $this->exportCsv($request, $type);
        }

        return response()->download($fullXlsxPath)->deleteFileAfterSend(true);
    }

    /**
     * Export Full Database Backup (JSON dump of main tables)
     * 
     * @authenticated
     */
    public function exportDatabase(Request $request)
    {
        $tables = [
            'users',
            'branches',
            'role_permissions',
            'employees',
            'patients',
            'treatments',
            'treatment_plans',
            'invoices',
            'invoice_treatments',
            'invoice_other_charges',
            'payment_transactions',
            'appointments',
            'inventory_items',
            'medicines',
            'medicine_units',
            'expenses',
            'medical_histories',
            'insurance_companies',
            'insurance_packages',
            'insurance_claims'
        ];

        $backupData = [
            'app_name' => config('app.name', 'ChartBackend'),
            'exported_at' => now()->toIso8601String(),
            'version' => '1.0',
            'tables' => []
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $backupData['tables'][$table] = DB::table($table)->get()->toArray();
            }
        }

        $filename = "database_backup_" . date('Y-m-d_His') . ".json";

        return response()->streamDownload(function () use ($backupData) {
            echo json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Import Full Database Backup (JSON)
     * 
     * @authenticated
     */
    public function importDatabase(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:json,txt|max:512000', // 500MB max
            'mode' => 'nullable|string|in:replace,skip', // replace existing by primary key or insert
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());
        $data = json_decode($content, true);

        if (!$data || !isset($data['tables']) || !is_array($data['tables'])) {
            return response()->json([
                'error' => 'Invalid backup file format. Expected a JSON structure containing a "tables" key.'
            ], 422);
        }

        $mode = $request->input('mode', 'replace');
        $restored = [];

        DB::beginTransaction();
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            foreach ($data['tables'] as $table => $rows) {
                if (!Schema::hasTable($table) || empty($rows)) {
                    continue;
                }

                $count = 0;
                $chunks = array_chunk($rows, 250);

                foreach ($chunks as $chunk) {
                    if ($mode === 'replace') {
                        // Insert or ignore / replace
                        foreach ($chunk as $row) {
                            DB::table($table)->updateOrInsert(
                                ['id' => $row['id']],
                                $row
                            );
                            $count++;
                        }
                    } else {
                        DB::table($table)->insertOrIgnore($chunk);
                        $count += count($chunk);
                    }
                }

                $restored[$table] = $count;
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            DB::commit();

            return response()->json([
                'message' => 'Database backup imported successfully.',
                'restored_tables' => $restored
            ]);
        } catch (\Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to restore database: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Define columns for each export entity
     */
    protected function getExportColumns(string $type): array
    {
        switch ($type) {
            case 'patients':
                return [
                    'Patient ID' => 'id',
                    'First Name' => 'first_name',
                    'Last Name' => 'last_name',
                    'Email' => 'email',
                    'Phone' => 'phone',
                    'Gender' => 'gender',
                    'Date of Birth' => 'date_of_birth',
                    'Age' => 'age',
                    'Nationality' => 'nationality',
                    'Address' => 'address',
                    'Branch' => fn($p) => $p->branch?->name ?? $p->branch_id,
                    'Status' => 'status',
                    'Last Visit' => 'last_visit',
                    'Next Visit' => 'next_visit',
                    'History / Notes' => 'history',
                    'Created At' => 'created_at',
                ];

            case 'employees':
                return [
                    'Employee ID' => 'id',
                    'Name' => 'name',
                    'Email' => 'email',
                    'Phone' => 'phone',
                    'Role' => 'role',
                    'Branch' => fn($e) => $e->branch?->name ?? $e->branch_id,
                    'Salary' => 'salary',
                    'Hire Date' => 'hire_date',
                    'Status' => 'status',
                    'Created At' => 'created_at',
                ];

            case 'treatments':
                return [
                    'Code' => 'treatment_code',
                    'Procedure' => 'procedure',
                    'Category' => 'category',
                    'Cost' => 'cost',
                    'Created At' => 'created_at',
                ];

            case 'invoices':
                return [
                    'Invoice #' => 'receipt_number',
                    'Patient Name' => 'patient_name',
                    'Total Amount' => 'amount',
                    'Paid Amount' => 'paid_amount',
                    'Discount' => 'discount',
                    'Tax' => 'tax',
                    'Patient Balance' => 'patient_balance',
                    'Insurance Coverage' => 'insurance_coverage',
                    'Status' => 'status',
                    'Payment Method' => 'payment_method',
                    'Issue Date' => 'issue_date',
                    'Due Date' => 'due_date',
                    'Category' => 'category',
                    'Branch' => fn($i) => $i->branch?->name ?? $i->branch_id,
                ];

            case 'inventory':
                return [
                    'Item ID' => 'id',
                    'Item Name' => 'name',
                    'Supplier' => 'supplier',
                    'Stock Level' => 'stock',
                    'Reorder Level' => 'reorder_level',
                    'Unit Price' => 'price',
                    'Status' => 'status',
                    'Branch' => fn($item) => $item->branch?->name ?? $item->branch_id,
                ];

            case 'medicines':
                return [
                    'Medicine ID' => 'id',
                    'Name' => 'name',
                    'Category' => 'category',
                    'Description' => 'description',
                    'Branch' => fn($m) => $m->branch?->name ?? $m->branch_id,
                ];

            case 'appointments':
                return [
                    'Appointment ID' => 'id',
                    'Patient Name' => 'patient_name',
                    'Doctor Name' => 'doctor_name',
                    'Treatment' => 'treatment',
                    'Date' => 'date',
                    'Time' => 'time',
                    'Token Number' => 'token_number',
                    'Status' => 'status',
                    'Branch' => fn($a) => $a->branch?->name ?? $a->branch_id,
                ];

            case 'expenses':
                return [
                    'Expense ID' => 'id',
                    'Date' => 'date',
                    'Category' => 'category',
                    'Description' => 'description',
                    'Amount' => 'amount',
                    'Branch' => fn($exp) => $exp->branch?->name ?? $exp->branch_id,
                ];

            case 'medical_history':
                return [
                    'History ID' => 'id',
                    'Patient Name' => fn($m) => $m->patient ? ($m->patient->first_name . ' ' . $m->patient->last_name) : 'Patient #' . $m->patient_id,
                    'Patient Email' => fn($m) => $m->patient?->email ?? '',
                    'Patient Phone' => fn($m) => $m->patient?->phone ?? '',
                    'Date' => 'date',
                    'Chief Complaint' => 'chief_complaint',
                    'History of Complaint' => 'history_complaint',
                    'Extra Oral Exam' => 'extra_oral_exam',
                    'Intra Oral Exam' => 'intra_oral_exam',
                    'Diagnosis' => 'diagnosis',
                    'Treatment Plan' => 'treatment_plan',
                    'Treatment Given' => 'treatment_given',
                    'Consent' => fn($m) => $m->consent ? 'Yes' : 'No',
                    'Consent Notes' => 'consent_notes',
                    'Doctor Name' => 'doctor_name',
                    'Treatment Note' => 'treatment_note',
                    'Created At' => 'created_at',
                ];

            case 'lab_orders':
                return [
                    'Order ID' => 'id',
                    'Client / Lab' => fn($l) => $l->client?->name ?? 'Client #' . $l->client_id,
                    'Doctor Name' => 'doctor_name',
                    'Item' => 'item',
                    'Tooth' => 'tooth',
                    'Status' => 'status',
                    'Shade' => 'shade',
                    'Material' => 'material',
                    'Sent Date' => 'sent_date',
                    'Due Date' => 'due_date',
                    'Received Date' => 'received_date',
                    'Applied Price' => 'applied_price',
                    'Instructions' => 'instructions',
                    'Branch' => fn($l) => $l->branch?->name ?? $l->branch_id,
                    'Created At' => 'created_at',
                ];

            default:
                return [];
        }
    }

    /**
     * Build filtered query for entity
     */
    protected function buildQuery(Request $request, string $type)
    {
        switch ($type) {
            case 'patients':
                $query = Patient::with('branch');
                if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
                if ($request->filled('status')) $query->where('status', $request->status);
                return $query->latest('id');

            case 'employees':
                $query = Employee::with('branch');
                if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
                if ($request->filled('status')) $query->where('status', $request->status);
                return $query->latest('id');

            case 'treatments':
                $query = Treatment::query();
                if ($request->filled('category')) $query->where('category', $request->category);
                return $query->latest('id');

            case 'invoices':
                $query = Invoice::with('branch');
                if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
                if ($request->filled('status')) $query->where('status', $request->status);
                if ($request->filled('start_date')) $query->whereDate('issue_date', '>=', $request->start_date);
                if ($request->filled('end_date')) $query->whereDate('issue_date', '<=', $request->end_date);
                return $query->latest('id');

            case 'inventory':
                $query = InventoryItem::with('branch');
                if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
                if ($request->filled('status')) $query->where('status', $request->status);
                return $query->latest('id');

            case 'medicines':
                $query = Medicine::with('branch');
                if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
                return $query->latest('id');

            case 'appointments':
                $query = Appointment::with('branch');
                if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
                if ($request->filled('status')) $query->where('status', $request->status);
                if ($request->filled('start_date')) $query->whereDate('date', '>=', $request->start_date);
                if ($request->filled('end_date')) $query->whereDate('date', '<=', $request->end_date);
                return $query->latest('id');

            case 'expenses':
                $query = Expense::with('branch');
                if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
                if ($request->filled('start_date')) $query->whereDate('date', '>=', $request->start_date);
                if ($request->filled('end_date')) $query->whereDate('date', '<=', $request->end_date);
                return $query->latest('id');

            case 'medical_history':
                $query = MedicalHistory::with('patient');
                if ($request->filled('start_date')) $query->whereDate('date', '>=', $request->start_date);
                if ($request->filled('end_date')) $query->whereDate('date', '<=', $request->end_date);
                return $query->latest('id');

            case 'lab_orders':
                $query = LaboratoryOrder::with(['client', 'branch']);
                if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
                if ($request->filled('status')) $query->where('status', $request->status);
                if ($request->filled('start_date')) $query->whereDate('sent_date', '>=', $request->start_date);
                if ($request->filled('end_date')) $query->whereDate('sent_date', '<=', $request->end_date);
                return $query->latest('id');

            default:
                return DB::table($type);
        }
    }
}
