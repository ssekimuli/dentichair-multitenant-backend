<?php

namespace App\Services\Import;

use App\Jobs\ProcessUniversalImportJob;
use App\Models\ImportJob;
use App\Models\ImportRowError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

use App\Services\Import\ImportErrorHumanizer;

class UniversalImportService
{
    protected SpreadsheetReaderService $reader;
    protected ImportSchemaService $schemaService;
    protected ImportMappingService $mappingService;
    protected ImportValidationService $validationService;
    protected ImportErrorHumanizer $errorHumanizer;

    public function __construct(
        SpreadsheetReaderService $reader,
        ImportSchemaService $schemaService,
        ImportMappingService $mappingService,
        ImportValidationService $validationService,
        ?ImportErrorHumanizer $errorHumanizer = null
    ) {
        $this->reader = $reader;
        $this->schemaService = $schemaService;
        $this->mappingService = $mappingService;
        $this->validationService = $validationService;
        $this->errorHumanizer = $errorHumanizer ?? new ImportErrorHumanizer($schemaService);
    }

    /**
     * Generate preview from uploaded spreadsheet.
     */
    public function generatePreview(
        UploadedFile $file,
        string $table,
        ?string $sheet = null,
        $user = null,
        ?int $branchId = null
    ): array
    {
        $table = $this->schemaService->normalizeTableName($table);
        if (!$this->schemaService->isTableAuthorized($table, $user)) {
            throw new \Illuminate\Auth\Access\AuthorizationException("You are not authorized to import into table '{$table}'.");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = Config::get('import.allowed_extensions', ['csv', 'txt', 'xlsx', 'xls']);

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException("Invalid file extension '.{$extension}'. Allowed: " . implode(', ', $allowedExtensions));
        }

        $tempDisk = Config::get('import.temp_disk', 'local');
        $tempDir = Config::get('import.temp_directory', 'imports');
        $storedPath = $file->store(app(\App\Services\TenantContext::class)->storagePath($tempDir), $tempDisk);
        $fullPath = Storage::disk($tempDisk)->path($storedPath);

        // Inspect headers and sample rows
        $headers = $this->reader->getHeaders($fullPath, $sheet, $table);
        if (empty($headers)) {
            Storage::disk($tempDisk)->delete($storedPath);
            throw new \InvalidArgumentException('Unable to parse file headers. The file may be empty or corrupted.');
        }

        $totalRows = $this->reader->countRows($fullPath, $sheet, $table);
        $sampleRows = $this->reader->getPreviewRows($fullPath, 5, $sheet, $table);
        $analysis = $this->mappingService->proposeMapping($headers, $table);

        $effectiveBranchId = $branchId ?: ($user?->branch_id ?? null);

        // Create pending import job record
        $job = ImportJob::create([
            'user_id' => $user?->id,
            'branch_id' => $effectiveBranchId,
            'table_name' => $table,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'file_type' => $extension,
            'sheet_name' => $sheet,
            'status' => 'pending',
            'total_rows' => $totalRows,
            'mapping' => $analysis['mapping'],
        ]);

        return [
            'import_id' => $job->id,
            'branch_id' => $job->branch_id,
            'table' => $table,
            'headers' => $headers,
            'mapping' => $analysis['mapping'],
            'suggestions' => $analysis['suggestions'],
            'unmapped_headers' => $analysis['unmapped_headers'],
            'missing_required_fields' => $analysis['missing_required_fields'],
            'sample_rows' => $sampleRows,
            'total_rows' => $totalRows,
        ];
    }

    /**
     * Prepare and start execution of an import job.
     */
    public function executeImport(
        array $params,
        $user = null,
        ?UploadedFile $file = null
    ): ImportJob {
        $table = $this->schemaService->normalizeTableName($params['table']);

        if (!$this->schemaService->isTableAuthorized($table, $user)) {
            throw new \Illuminate\Auth\Access\AuthorizationException("You are not authorized to import into table '{$table}'.");
        }

        $mapping = $params['mapping'] ?? [];
        $validation = $this->mappingService->validateMapping($mapping, $table);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException(implode(' ', $validation['errors']));
        }
        $mapping = $validation['resolved_mapping'] ?? $mapping;

        $mode = $params['mode'] ?? 'insert';
        $errorMode = $params['error_mode'] ?? 'partial';
        $updateKey = $params['update_key'] ?? null;

        $tableConfig = $this->schemaService->getTableConfig($table);
        $allowedUnique = $tableConfig['unique_by'] ?? ['id'];

        if (in_array($mode, ['update_existing', 'upsert', 'skip_duplicates'], true)) {
            if (!$updateKey) {
                $updateKey = $tableConfig['default_unique_by'] ?? 'id';
            }
            if (!in_array($updateKey, $allowedUnique, true)) {
                throw new \InvalidArgumentException("Invalid update key '{$updateKey}'. Allowed for table '{$table}': " . implode(', ', $allowedUnique));
            }
        }

        $tempDisk = Config::get('import.temp_disk', 'local');
        $importJob = null;
        $targetBranchId = !empty($params['branch_id']) ? (int)$params['branch_id'] : null;

        // Either reuse preview job or create a new one from uploaded file
        if (!empty($params['import_id'])) {
            $importJob = ImportJob::where('id', $params['import_id'])->first();
            if (!$importJob) {
                throw new \InvalidArgumentException("Import job '{$params['import_id']}' not found.");
            }
            if ($importJob->table_name !== $table) {
                throw new \InvalidArgumentException("Import job table does not match provided table.");
            }
            if ($targetBranchId) {
                $importJob->update(['branch_id' => $targetBranchId]);
            }
        } elseif ($file) {
            $tempDir = Config::get('import.temp_directory', 'imports');
            $storedPath = $file->store(app(\App\Services\TenantContext::class)->storagePath($tempDir), $tempDisk);
            $fullPath = Storage::disk($tempDisk)->path($storedPath);
            $totalRows = $this->reader->countRows($fullPath, $params['sheet'] ?? null, $table);

            $importJob = ImportJob::create([
                'user_id' => $user?->id,
                'branch_id' => $targetBranchId ?: ($user?->branch_id ?? null),
                'table_name' => $table,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'file_type' => strtolower($file->getClientOriginalExtension()),
                'sheet_name' => $params['sheet'] ?? null,
                'total_rows' => $totalRows,
            ]);
        } else {
            throw new \InvalidArgumentException('Either an import_id or a file must be provided.');
        }

        // Update job parameters
        $importJob->update([
            'status' => 'queued',
            'mode' => $mode,
            'error_mode' => $errorMode,
            'update_key' => $updateKey,
            'mapping' => $mapping,
            'options' => $params['options'] ?? null,
        ]);

        $asyncThreshold = Config::get('import.async_threshold', 300);
        $shouldQueue = isset($params['async']) ? (bool)$params['async'] : ($importJob->total_rows >= $asyncThreshold);

        if ($shouldQueue) {
            ProcessUniversalImportJob::dispatch($importJob->id);
            if (config('queue.default') === 'sync') {
                $importJob->refresh();
            }
        } else {
            // Process synchronously
            $this->processJob($importJob);
            $importJob->refresh();
        }

        return $importJob;
    }

    /**
     * Process an import job end to end (used by both sync execution and queued worker).
     */
    public function processJob(ImportJob $job): void
    {
        $tempDisk = Config::get('import.temp_disk', 'local');
        $fullPath = Storage::disk($tempDisk)->path($job->file_path);

        if (!file_exists($fullPath)) {
            $job->update([
                'status' => 'failed',
                'error_summary' => 'Import file not found or temporary file has expired.',
                'completed_at' => now(),
            ]);
            return;
        }

        $job->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $table = $this->schemaService->normalizeTableName($job->table_name);
        $mapping = $job->mapping ?? [];
        $mode = $job->mode;
        $errorMode = $job->error_mode;
        $updateKey = $job->update_key;
        $chunkSize = Config::get('import.chunk_size', 500);
        $tableConfig = $this->schemaService->getTableConfig($table) ?? [];
        $tenantColumn = $tableConfig['tenant_column'] ?? null;
        $branchId = $job->branch_id;

        // Invert mapping for error reporting: [destination_field => source_header]
        $fieldToHeaderMap = array_flip(array_filter($mapping));

        $user = $job->user;
        $totalProcessed = 0;
        $importedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        $hasAtomicFailure = false;
        $atomicFailureMessage = null;

        try {
            $this->reader->processChunks(
                $fullPath,
                $chunkSize,
                function (array $chunk, array $headers) use (
                    $job,
                    $table,
                    $mapping,
                    $fieldToHeaderMap,
                    $mode,
                    $errorMode,
                    $updateKey,
                    $tenantColumn,
                    $branchId,
                    $user,
                    &$totalProcessed,
                    &$importedCount,
                    &$updatedCount,
                    &$skippedCount,
                    &$failedCount,
                    &$hasAtomicFailure,
                    &$atomicFailureMessage
                ) {
                    if ($hasAtomicFailure) {
                        return;
                    }

                    $now = now();
                    $validRows = [];
                    $batchErrors = [];

                    foreach ($chunk as $rowItem) {
                        $rowNumber = $rowItem['row_number'];
                        $rawData = $rowItem['data'];

                        // Extract mapped fields
                        $mappedData = [];
                        foreach ($mapping as $sourceHeader => $destField) {
                            if (!empty($destField)) {
                                $mappedData[$destField] = $rawData[$sourceHeader] ?? null;
                            }
                        }

                        // Normalize row with selected branch
                        $normalized = $this->validationService->normalizeRow($mappedData, $table, $user, $branchId, $rawData);

                        // Validate row
                        $rowErrors = $this->validationService->validateRow($normalized, $rowNumber, $table, $fieldToHeaderMap);

                        if (!empty($rowErrors)) {
                            foreach ($rowErrors as $err) {
                                $err['import_job_id'] = $job->id;
                                $err['raw_data'] = is_array($rawData) ? json_encode($rawData, JSON_UNESCAPED_UNICODE) : $rawData;
                                $err['created_at'] = $now;
                                $batchErrors[] = $err;
                            }
                            $failedCount++;

                            if ($errorMode === 'atomic') {
                                $hasAtomicFailure = true;
                                $atomicFailureMessage = "Row {$rowNumber} validation failed: " . ($rowErrors[0]['error_message'] ?? 'Validation error');
                                break;
                            }
                            continue;
                        }

                        $validRows[] = [
                            'row_number' => $rowNumber,
                            'data' => $normalized,
                            'raw' => $rawData,
                        ];
                    }

                    // Save row errors
                    if (!empty($batchErrors)) {
                        ImportRowError::insert($batchErrors);
                    }

                    if ($hasAtomicFailure) {
                        return;
                    }

                    // Write valid rows to database
                    if (!empty($validRows)) {
                        $this->writeRows(
                            $table,
                            $validRows,
                            $mode,
                            $updateKey,
                            $tenantColumn,
                            $branchId,
                            $job->id,
                            $fieldToHeaderMap,
                            $importedCount,
                            $updatedCount,
                            $skippedCount,
                            $failedCount,
                            $hasAtomicFailure,
                            $atomicFailureMessage,
                            $errorMode
                        );
                    }

                    $totalProcessed += count($chunk);

                    // Update progress in database
                    $job->update([
                        'processed_rows' => $totalProcessed,
                        'imported_count' => $importedCount,
                        'updated_count' => $updatedCount,
                        'skipped_count' => $skippedCount,
                        'failed_count' => $failedCount,
                    ]);
                },
                $job->sheet_name,
                $table
            );

            if ($hasAtomicFailure) {
                $job->update([
                    'status' => 'failed',
                    'processed_rows' => $totalProcessed,
                    'imported_count' => $importedCount,
                    'updated_count' => $updatedCount,
                    'skipped_count' => $skippedCount,
                    'failed_count' => $failedCount,
                    'error_summary' => $atomicFailureMessage,
                    'completed_at' => now(),
                ]);
            } else {
                $job->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Universal Import execution failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $friendlySummary = $this->errorHumanizer->humanizeSummary($e);
            $job->update([
                'status' => 'failed',
                'error_summary' => 'Import stopped: ' . $friendlySummary,
                'completed_at' => now(),
            ]);
        } finally {
            // Cleanup temp file if configured
            if (Config::get('import.cleanup_temp_files', true) && file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    /**
     * Write batch of validated rows to destination database table according to mode.
     */
    protected function writeRows(
        string $table,
        array $validRows,
        string $mode,
        ?string $updateKey,
        ?string $tenantColumn,
        ?int $branchId,
        string $jobId,
        array $fieldToHeaderMap,
        int &$importedCount,
        int &$updatedCount,
        int &$skippedCount,
        int &$failedCount,
        bool &$hasAtomicFailure,
        ?string &$atomicFailureMessage,
        string $errorMode
    ): void {
        $now = now();
        $hasTimestamps = Schema::hasColumn($table, 'created_at') && Schema::hasColumn($table, 'updated_at');

        DB::transaction(function () use (
            $table,
            $validRows,
            $mode,
            $updateKey,
            $tenantColumn,
            $branchId,
            $jobId,
            $fieldToHeaderMap,
            &$importedCount,
            &$updatedCount,
            &$skippedCount,
            &$failedCount,
            &$hasAtomicFailure,
            &$atomicFailureMessage,
            $errorMode,
            $now,
            $hasTimestamps
        ) {
            foreach ($validRows as $item) {
                $rowNumber = $item['row_number'];
                $data = $item['data'];
                $rawData = $item['raw'];

                try {
                    // Check if record already exists in database (by update_key, contact/phone, or email)
                    $existing = $this->findExistingRecord(
                        $table,
                        $data,
                        $updateKey,
                        $tenantColumn,
                        $branchId
                    );

                    if ($existing) {
                        // Record ALREADY EXISTS:
                        if ($mode === 'skip_duplicates') {
                            $skippedCount++;
                            continue;
                        }

                        // For 'update_existing', 'upsert', OR 'insert' (when record already exists):
                        // "If contact already exists, JUST UPDATE!"
                        if ($hasTimestamps) {
                            $data['updated_at'] = $now;
                        }
                        unset($data['created_at']);

                        // Preserve existing values if incoming data is empty/null or if incoming was just populated by fallback table defaults
                        $tableDefaults = (array) config("import.tables.{$table}.defaults", []);
                        foreach (['email', 'phone', 'first_name', 'last_name', 'gender', 'date_of_birth', 'address', 'nationality'] as $preserveCol) {
                            if (empty($existing->{$preserveCol})) {
                                continue;
                            }
                            if (!array_key_exists($preserveCol, $data) || $data[$preserveCol] === null || $data[$preserveCol] === '') {
                                unset($data[$preserveCol]);
                                continue;
                            }
                            // Date of birth given as a word ("Adult", "Child"): keep the stored date of birth and age
                            if ($preserveCol === 'date_of_birth') {
                                $dobHeader = $fieldToHeaderMap['date_of_birth'] ?? null;
                                $rawDob = ($dobHeader !== null && isset($rawData[$dobHeader])) ? (string)$rawData[$dobHeader] : '';
                                if ($this->validationService->ageForDateOfBirthWord($rawDob) !== null) {
                                    unset($data['date_of_birth']);
                                    if (!isset($fieldToHeaderMap['age'])) {
                                        unset($data['age']);
                                    }
                                    continue;
                                }
                            }
                            if (isset($tableDefaults[$preserveCol]) && $data[$preserveCol] === $tableDefaults[$preserveCol]) {
                                $header = $fieldToHeaderMap[$preserveCol] ?? null;
                                if ($header === null || !isset($rawData[$header]) || trim((string)$rawData[$header]) === '') {
                                    unset($data[$preserveCol]);
                                }
                            }
                        }

                        $tableColumns = Schema::getColumnListing($table);
                        $cleanData = array_intersect_key($data, array_flip($tableColumns));

                        if ($table === 'invoices') {
                            $amt = isset($cleanData['amount']) ? (float)$cleanData['amount'] : (float)($existing->amount ?? 0);
                            $p = isset($cleanData['paid_amount']) ? (float)$cleanData['paid_amount'] : (float)($existing->paid_amount ?? 0);
                            $cleanData['patient_responsibility'] = $amt;
                            $cleanData['patient_paid_amount'] = $p;
                            $cleanData['patient_balance'] = max(0, $amt - $p);
                            if ($cleanData['patient_balance'] > 0) {
                                $cleanData['status'] = 'Pending';
                            } elseif ($amt > 0 && $cleanData['patient_balance'] <= 0) {
                                $cleanData['status'] = 'Paid';
                            }
                        }

                        DB::table($table)->where('id', $existing->id)->update($cleanData);

                        if ($table === 'patients' && array_key_exists('next_visit', $cleanData)) {
                            $this->scheduleNextVisitAppointment((int)$existing->id, $cleanData['next_visit'], $branchId, $jobId);
                        }

                        if ($table === 'patients') {
                            $this->createMedicalHistoryFromPatientRow((int)$existing->id, $cleanData);
                        }

                        if ($table === 'laboratory_orders') {
                            $this->handleLabOrderInvoice($existing->id, $cleanData, $data, $branchId, $now);
                        }

                        if ($table === 'medical_histories' && !empty($data['_patient_demographics'])) {
                            $pId = $cleanData['patient_id'] ?? $existing->patient_id ?? $data['patient_id'] ?? null;
                            if ($pId) {
                                $this->updatePatientDemographics((int)$pId, $data['_patient_demographics']);
                            }
                        }

                        if ($table === 'invoices' && !empty($data['_invoice_treatment'])) {
                            $hasTreatments = DB::table('invoice_treatments')->where('invoice_id', $existing->id)->exists();
                            if (!$hasTreatments) {
                                $treatmentProc = $data['_invoice_treatment'];
                                $cost = (float)($cleanData['amount'] ?? $existing->amount ?? 0);
                                $procedureId = null;
                                $treatmentModel = DB::table('treatments')->where('procedure', $treatmentProc)->first();
                                if ($treatmentModel) {
                                    $procedureId = $treatmentModel->id;
                                }
                                DB::table('invoice_treatments')->insert([
                                    'invoice_id' => $existing->id,
                                    'procedure' => $treatmentProc,
                                    'procedure_id' => $procedureId,
                                    'cost' => $cost,
                                    'quantity' => 1,
                                    'unit_price' => $cost,
                                    'total_amount' => $cost,
                                    'patient_amount' => $cost,
                                    'insurance_amount' => 0,
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                            }
                        }

                        $updatedCount++;
                        continue;
                    }

                    // Record DOES NOT EXIST:
                    if ($mode === 'update_existing') {
                        // Cannot update non-existent record
                        $skippedCount++;
                        continue;
                    }

                    // For 'insert' or 'upsert': create new record
                    if ($hasTimestamps) {
                        $data['created_at'] = $now;
                        $data['updated_at'] = $now;
                    }

                    $tableColumns = Schema::getColumnListing($table);
                    $cleanData = array_intersect_key($data, array_flip($tableColumns));

                    if ($table === 'medical_histories') {
                        if (empty($cleanData['client_uuid']) && Schema::hasColumn($table, 'client_uuid')) {
                            $cleanData['client_uuid'] = (string) Str::uuid();
                        }
                        if (empty($cleanData['date'])) {
                            $cleanData['date'] = $now->toDateString();
                        }
                        if (!isset($cleanData['consent'])) {
                            $cleanData['consent'] = true;
                        }
                    }

                    if ($table === 'invoices') {
                        if (empty($cleanData['client_uuid']) && Schema::hasColumn($table, 'client_uuid')) {
                            $cleanData['client_uuid'] = (string) Str::uuid();
                        }
                        if (empty($cleanData['receipt_number'])) {
                            $cleanData['receipt_number'] = 'REC-' . strtoupper(Str::random(8));
                        }
                        if (empty($cleanData['issue_date'])) {
                            $cleanData['issue_date'] = $now->toDateString();
                        }
                        if (empty($cleanData['due_date'])) {
                            $cleanData['due_date'] = $cleanData['issue_date'];
                        }
                        if (empty($cleanData['status'])) {
                            $cleanData['status'] = 'Paid';
                        }
                        if (!isset($cleanData['amount'])) {
                            $cleanData['amount'] = 0.00;
                        }
                        if (!isset($cleanData['insurance_responsibility'])) {
                            $cleanData['insurance_responsibility'] = 0.00;
                        }
                        if (!isset($cleanData['insurance_paid_amount'])) {
                            $cleanData['insurance_paid_amount'] = 0.00;
                        }
                        if (!isset($cleanData['insurance_balance'])) {
                            $cleanData['insurance_balance'] = 0.00;
                        }
                        if (!isset($cleanData['patient_responsibility'])) {
                            $cleanData['patient_responsibility'] = $cleanData['amount'];
                        }
                        if (!isset($cleanData['patient_paid_amount'])) {
                            $cleanData['patient_paid_amount'] = $cleanData['paid_amount'] ?? 0.00;
                        }
                        $paid = (float)($cleanData['patient_paid_amount'] ?? 0);
                        $cleanData['patient_balance'] = max(0, (float)$cleanData['patient_responsibility'] - $paid);
                        $totalBalance = (float)$cleanData['patient_balance'] + (float)($cleanData['insurance_balance'] ?? 0);

                        // If the imported invoice still has a balance after making calculation, make status Pending
                        if ($totalBalance > 0) {
                            $cleanData['status'] = 'Pending';
                        } elseif ((float)$cleanData['amount'] > 0 && $totalBalance <= 0) {
                            $cleanData['status'] = 'Paid';
                        } elseif (empty($cleanData['status'])) {
                            $cleanData['status'] = 'Paid';
                        }

                        if (empty($cleanData['branch_id'])) {
                            $cleanData['branch_id'] = $branchId ?: 1;
                            if (!DB::table('branches')->where('id', $cleanData['branch_id'])->exists()) {
                                $cleanData['branch_id'] = (int)(DB::table('branches')->value('id') ?? 1);
                            }
                        }
                    }

                    if ($table === 'laboratory_orders') {
                        if (empty($cleanData['branch_id'])) {
                            $cleanData['branch_id'] = $branchId ?: 1;
                            if (!DB::table('branches')->where('id', $cleanData['branch_id'])->exists()) {
                                $cleanData['branch_id'] = (int)(DB::table('branches')->value('id') ?? 1);
                            }
                        }
                        if (empty($cleanData['status'])) {
                            $cleanData['status'] = 'Received';
                        }
                    }

                    $insertedId = DB::table($table)->insertGetId($cleanData);

                    // Remember created rows so this import can be undone later
                    if (in_array($table, ['patients', 'invoices', 'appointments'], true)) {
                        $this->recordCreatedRecord($jobId, $table, (int) $insertedId);
                    }

                    if ($table === 'patients') {
                        $this->scheduleNextVisitAppointment((int)$insertedId, $cleanData['next_visit'] ?? null, $branchId, $jobId);
                        $this->createMedicalHistoryFromPatientRow((int)$insertedId, $cleanData);
                    }

                    if ($table === 'laboratory_orders') {
                        $this->handleLabOrderInvoice($insertedId, $cleanData, $data, $branchId, $now);
                    }

                    if ($table === 'medical_histories' && !empty($data['_patient_demographics'])) {
                        $pId = $cleanData['patient_id'] ?? $data['patient_id'] ?? null;
                        if ($pId) {
                            $this->updatePatientDemographics((int)$pId, $data['_patient_demographics']);
                        }
                    }

                    if ($table === 'invoices' && !empty($data['_invoice_treatment'])) {
                        $treatmentProc = $data['_invoice_treatment'];
                        $cost = (float)($cleanData['amount'] ?? 0);
                        $procedureId = null;
                        $treatmentModel = DB::table('treatments')->where('procedure', $treatmentProc)->first();
                        if ($treatmentModel) {
                            $procedureId = $treatmentModel->id;
                        }
                        DB::table('invoice_treatments')->insert([
                            'invoice_id' => $insertedId,
                            'procedure' => $treatmentProc,
                            'procedure_id' => $procedureId,
                            'cost' => $cost,
                            'quantity' => 1,
                            'unit_price' => $cost,
                            'total_amount' => $cost,
                            'patient_amount' => $cost,
                            'insurance_amount' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $importedCount++;
                } catch (\Throwable $e) {
                    // Auto-recovery for any duplicate constraint collision: locate existing record and update it!
                    if ($this->errorHumanizer->isDuplicateEntryError($e->getMessage())) {
                        $duplicateExisting = $this->findExistingRecordByDuplicateError(
                            $e->getMessage(),
                            $table,
                            $data,
                            $tenantColumn,
                            $branchId
                        );

                        if ($duplicateExisting) {
                            if ($hasTimestamps) {
                                $data['updated_at'] = $now;
                            }
                            unset($data['created_at']);
                            $tableDefaults = (array) config("import.tables.{$table}.defaults", []);
                            foreach (['email', 'phone', 'first_name', 'last_name', 'gender', 'date_of_birth', 'address', 'nationality'] as $preserveCol) {
                                if (empty($duplicateExisting->{$preserveCol})) {
                                    continue;
                                }
                                if (!array_key_exists($preserveCol, $data) || $data[$preserveCol] === null || $data[$preserveCol] === '') {
                                    unset($data[$preserveCol]);
                                    continue;
                                }
                                if (isset($tableDefaults[$preserveCol]) && $data[$preserveCol] === $tableDefaults[$preserveCol]) {
                                    $header = $fieldToHeaderMap[$preserveCol] ?? null;
                                    if ($header === null || !isset($rawData[$header]) || trim((string)$rawData[$header]) === '') {
                                        unset($data[$preserveCol]);
                                    }
                                }
                            }

                            $tableColumns = Schema::getColumnListing($table);
                            $cleanData = array_intersect_key($data, array_flip($tableColumns));

                            if ($table === 'invoices') {
                                $amt = isset($cleanData['amount']) ? (float)$cleanData['amount'] : (float)($duplicateExisting->amount ?? 0);
                                $p = isset($cleanData['paid_amount']) ? (float)$cleanData['paid_amount'] : (float)($duplicateExisting->paid_amount ?? 0);
                                $cleanData['patient_responsibility'] = $amt;
                                $cleanData['patient_paid_amount'] = $p;
                                $cleanData['patient_balance'] = max(0, $amt - $p);
                                if ($cleanData['patient_balance'] > 0) {
                                    $cleanData['status'] = 'Pending';
                                } elseif ($amt > 0 && $cleanData['patient_balance'] <= 0) {
                                    $cleanData['status'] = 'Paid';
                                }
                            }

                            DB::table($table)->where('id', $duplicateExisting->id)->update($cleanData);

                            if ($table === 'laboratory_orders') {
                                $this->handleLabOrderInvoice($duplicateExisting->id, $cleanData, $data, $branchId, $now);
                            }

                            if ($table === 'medical_histories' && !empty($data['_patient_demographics'])) {
                                $pId = $cleanData['patient_id'] ?? $duplicateExisting->patient_id ?? $data['patient_id'] ?? null;
                                if ($pId) {
                                    $this->updatePatientDemographics((int)$pId, $data['_patient_demographics']);
                                }
                            }

                            if ($table === 'invoices' && !empty($data['_invoice_treatment'])) {
                                $hasTreatments = DB::table('invoice_treatments')->where('invoice_id', $duplicateExisting->id)->exists();
                                if (!$hasTreatments) {
                                    $treatmentProc = $data['_invoice_treatment'];
                                    $cost = (float)($cleanData['amount'] ?? $duplicateExisting->amount ?? 0);
                                    $procedureId = null;
                                    $treatmentModel = DB::table('treatments')->where('procedure', $treatmentProc)->first();
                                    if ($treatmentModel) {
                                        $procedureId = $treatmentModel->id;
                                    }
                                    DB::table('invoice_treatments')->insert([
                                        'invoice_id' => $duplicateExisting->id,
                                        'procedure' => $treatmentProc,
                                        'procedure_id' => $procedureId,
                                        'cost' => $cost,
                                        'quantity' => 1,
                                        'unit_price' => $cost,
                                        'total_amount' => $cost,
                                        'patient_amount' => $cost,
                                        'insurance_amount' => 0,
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ]);
                                }
                            }

                            $updatedCount++;
                            continue; // Successfully updated existing record!
                        }
                    }

                    $humanized = $this->errorHumanizer->humanize(
                        $e,
                        $table,
                        $data,
                        $fieldToHeaderMap
                    );

                    if ($errorMode === 'atomic') {
                        $hasAtomicFailure = true;
                        $atomicFailureMessage = "Import stopped at Row {$rowNumber}: " . $humanized['error_message'];
                        throw new \RuntimeException($atomicFailureMessage, 0, $e);
                    }

                    ImportRowError::create([
                        'import_job_id' => $jobId,
                        'row_number' => $rowNumber,
                        'source_column' => $humanized['source_column'],
                        'destination_field' => $humanized['destination_field'],
                        'error_code' => $humanized['error_code'],
                        'error_message' => $humanized['error_message'],
                        'raw_data' => $rawData,
                    ]);
                    $failedCount++;
                }
            }
        });
    }

    /**
     * Locate existing record in database by explicit update key, contact/phone, or email.
     */
    protected function findExistingRecord(
        string $table,
        array $data,
        ?string $updateKey,
        ?string $tenantColumn,
        ?int $branchId
    ): ?object {
        // 0. Appointments: the same patient on the same day is the same appointment, so update it
        if ($table === 'appointments' && !empty($data['patient_id']) && !empty($data['date'])) {
            try {
                $appointmentDate = Carbon::parse((string) $data['date'])->toDateString();
            } catch (\Throwable) {
                $appointmentDate = null;
            }

            if ($appointmentDate) {
                $query = DB::table('appointments')
                    ->where('patient_id', (int) $data['patient_id'])
                    ->whereDate('date', $appointmentDate);
                if ($tenantColumn && $branchId && Schema::hasColumn($table, $tenantColumn)) {
                    $scoped = (clone $query)->where($tenantColumn, $branchId)->first();
                    if ($scoped) {
                        return $scoped;
                    }
                }
                $record = $query->first();
                if ($record) {
                    return $record;
                }
            }
        }

        // 1. Explicit update_key match if configured and present in $data
        if (!empty($updateKey) && !empty($data[$updateKey]) && Schema::hasColumn($table, $updateKey)) {
            if (in_array($updateKey, ['phone', 'contact', 'telephone', 'mobile'], true)) {
                $found = $this->findRecordByPhone($table, (string)$data[$updateKey], $updateKey, $tenantColumn, $branchId, $this->personForPhoneMatch($table, $data));
                if ($found) {
                    return $found;
                }
            } else {
                $query = DB::table($table)->where($updateKey, $data[$updateKey]);
                if ($tenantColumn && $branchId && Schema::hasColumn($table, $tenantColumn)) {
                    $scoped = (clone $query)->where($tenantColumn, $branchId)->first();
                    if ($scoped) {
                        return $scoped;
                    }
                }
                $record = $query->first();
                if ($record) {
                    return $record;
                }
            }
        }

        // 2. Contact / Phone match (the user's core requirement: check contact if already exists, just update)
        $phoneCol = Schema::hasColumn($table, 'phone') ? 'phone' : (Schema::hasColumn($table, 'contact') ? 'contact' : null);
        $phoneVal = null;
        if ($phoneCol && !empty($data[$phoneCol])) {
            $phoneVal = $data[$phoneCol];
        } elseif (!empty($data['phone'])) {
            $phoneVal = $data['phone'];
            $phoneCol = Schema::hasColumn($table, 'phone') ? 'phone' : $phoneCol;
        } elseif (!empty($data['contact'])) {
            $phoneVal = $data['contact'];
            $phoneCol = Schema::hasColumn($table, 'contact') ? 'contact' : (Schema::hasColumn($table, 'phone') ? 'phone' : null);
        }

        if ($phoneCol && !empty($phoneVal)) {
            $found = $this->findRecordByPhone($table, (string)$phoneVal, $phoneCol, $tenantColumn, $branchId, $this->personForPhoneMatch($table, $data));
            if ($found) {
                return $found;
            }
        }

        // 3. Email match (if present and table has email column)
        if (Schema::hasColumn($table, 'email') && !empty($data['email'])) {
            $query = DB::table($table)->where('email', $data['email']);
            if ($tenantColumn && $branchId && Schema::hasColumn($table, $tenantColumn)) {
                $scoped = (clone $query)->where($tenantColumn, $branchId)->first();
                if ($scoped) {
                    return $scoped;
                }
            }
            $record = $query->first();
            if ($record) {
                return $record;
            }
        }

        // 4. ID match (if id is explicitly provided in data)
        if (Schema::hasColumn($table, 'id') && !empty($data['id'])) {
            $record = DB::table($table)->where('id', $data['id'])->first();
            if ($record) {
                return $record;
            }
        }

        return null;
    }

    /**
     * For patient imports, the name a phone match must agree with. Null for other tables.
     */
    protected function personForPhoneMatch(string $table, array $data): ?array
    {
        if ($table !== 'patients') {
            return null;
        }

        return [
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
        ];
    }

    /**
     * Find record by contact/phone with international & local format resilience.
     */
    protected function findRecordByPhone(
        string $table,
        string $phoneVal,
        string $phoneCol,
        ?string $tenantColumn,
        ?int $branchId,
        ?array $person = null
    ): ?object {
        $raw = trim($phoneVal);
        $digits = preg_replace('/\D+/', '', $raw);

        // Avoid matching ultra-short or placeholder values
        if (strlen($digits) < 6) {
            return null;
        }

        // Build candidate representations for fast indexed lookup
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

        $buildPhoneQuery = function () use ($table, $phoneCol, $candidates, $last9) {
            return DB::table($table)->where(function ($q) use ($phoneCol, $candidates, $last9) {
                $q->whereIn($phoneCol, $candidates);
                if ($last9) {
                    $q->orWhere($phoneCol, 'LIKE', '%' . $last9);
                }
            });
        };

        // Try within current branch first
        if ($tenantColumn && $branchId && Schema::hasColumn($table, $tenantColumn)) {
            $branchMatch = $this->firstPhoneMatch($buildPhoneQuery()->where($tenantColumn, $branchId), $person);
            if ($branchMatch) {
                return $branchMatch;
            }
        }

        // Try globally across table
        return $this->firstPhoneMatch($buildPhoneQuery(), $person);
    }

    /**
     * Return the first phone match. When a person is given (patient imports), only a record
     * with the same name counts, so relatives sharing one phone number stay separate patients.
     */
    protected function firstPhoneMatch($query, ?array $person): ?object
    {
        if ($person === null) {
            return $query->first();
        }

        foreach ($query->get() as $row) {
            if ($this->isSamePersonName($row, $person)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Names must match when both sides have one. Blank names and the default last name "Patient"
     * count as unknown, so they don't prevent a match.
     */
    protected function isSamePersonName(object $row, array $person): bool
    {
        // Placeholders are not names: "Imported" (blank first name default) and "Patient" (blank last name default)
        $placeholders = ['imported', 'patient'];
        $words = function (string $name) use ($placeholders): array {
            return array_values(array_diff($this->validationService->nameWords($name), $placeholders));
        };

        $incoming = $words(trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')));
        $existing = $words(trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')));

        // Unknown on either side does not block a match
        if (empty($incoming) || empty($existing)) {
            return true;
        }

        // Every word of the incoming name must appear in the stored name. Titles ("MS"), symbols
        // ("AHMED*") and word order do not matter; a different person sharing the phone does not match.
        return empty(array_diff($incoming, $existing));
    }

    /**
     * Find existing record when a unique database constraint violation occurs.
     */
    protected function findExistingRecordByDuplicateError(
        string $errorMessage,
        string $table,
        array $data,
        ?string $tenantColumn,
        ?int $branchId
    ): ?object {
        $info = $this->errorHumanizer->extractDuplicateInfo($errorMessage, $table);
        $field = $info['field'] ?? null;
        $val = $info['value'] ?? null;

        // 1. If both field and value were extracted from error message
        if (!empty($field) && !empty($val) && Schema::hasColumn($table, $field)) {
            $record = DB::table($table)->where($field, $val)->first();
            if ($record) {
                return $record;
            }
        }

        // 2. If value was extracted, locate column in $data matching that value
        if (!empty($val)) {
            foreach ($data as $col => $colVal) {
                if (is_scalar($colVal) && (string)$colVal === (string)$val && Schema::hasColumn($table, $col)) {
                    $record = DB::table($table)->where($col, $val)->first();
                    if ($record) {
                        return $record;
                    }
                }
            }
        }

        // 3. Fallback: resilient lookup by contact or email
        return $this->findExistingRecord($table, $data, null, $tenantColumn, $branchId);
    }

    /**
     * Generate CSV content with formula injection defense for downloading error report.
     */
    /**
     * Search the rows an import job created, so the user can check what was imported.
     * Returns up to 50 matches per table. Only rows created by this job are searched.
     *
     * @return array<string,array{total:int,rows:array<int,array<string,mixed>>}>
     */
    public function searchJobRecords(ImportJob $job, string $query = ''): array
    {
        $views = [
            'patients' => [
                'columns' => ['id', 'first_name', 'last_name', 'phone', 'email', 'gender', 'age', 'last_visit', 'next_visit'],
                'search' => ['first_name', 'last_name', 'phone', 'email'],
            ],
            'invoices' => [
                'columns' => ['id', 'receipt_number', 'patient_name', 'issue_date', 'amount', 'paid_amount', 'status'],
                'search' => ['receipt_number', 'patient_name', 'status'],
            ],
            'appointments' => [
                'columns' => ['id', 'patient_name', 'date', 'time', 'treatment', 'status'],
                'search' => ['patient_name', 'treatment', 'status'],
            ],
        ];

        $results = [];
        foreach ($views as $table => $view) {
            $ids = DB::table('import_job_records')
                ->where('import_job_id', $job->id)
                ->where('table_name', $table)
                ->where('action', 'created')
                ->pluck('record_id')
                ->all();

            $base = DB::table($table)->whereIn('id', $ids);
            if ($query !== '') {
                $term = '%' . addcslashes($query, '%_\\') . '%';
                $base->where(function ($q) use ($view, $term) {
                    foreach ($view['search'] as $column) {
                        $q->orWhere($column, 'like', $term);
                    }
                });
            }

            $total = (clone $base)->count();
            $rows = $base->orderBy('id')->limit(50)->get($view['columns'])
                ->map(fn ($row) => (array) $row)
                ->all();

            $results[$table] = ['total' => $total, 'rows' => $rows];
        }

        return $results;
    }

    /**
     * Remove the invoices and appointments an import created, so the file can be imported again.
     * Only rows created by this job are removed; rows it updated are left alone, and patients are never deleted.
     * Runs in one transaction: if the database refuses a delete, nothing is removed.
     *
     * @return array{deleted:array<string,int>}
     */
    public function undoImport(ImportJob $job): array
    {
        if ($job->status === 'undone') {
            throw new \InvalidArgumentException('This import has already been undone.');
        }

        if ($job->status !== 'completed') {
            throw new \InvalidArgumentException("Only completed imports can be undone (current status: {$job->status}).");
        }

        $createdIds = fn (string $table) => DB::table('import_job_records')
            ->where('import_job_id', $job->id)
            ->where('table_name', $table)
            ->where('action', 'created')
            ->pluck('record_id')
            ->all();

        $appointmentIds = $createdIds('appointments');
        $invoiceIds = $createdIds('invoices');

        if (empty($appointmentIds) && empty($invoiceIds)) {
            throw new \InvalidArgumentException('This import did not create any invoices or appointments that can be undone.');
        }

        $deleted = DB::transaction(function () use ($job, $appointmentIds, $invoiceIds) {
            $counts = ['appointments' => 0, 'invoices' => 0];

            if ($appointmentIds) {
                $counts['appointments'] = DB::table('appointments')->whereIn('id', $appointmentIds)->delete();
            }

            if ($invoiceIds) {
                // Insurance claims are kept; they are only unlinked from the removed invoices
                if (Schema::hasTable('insurance_claims')) {
                    DB::table('insurance_claims')->whereIn('invoice_id', $invoiceIds)->update(['invoice_id' => null]);
                }
                if (Schema::hasTable('invoice_treatments')) {
                    DB::table('invoice_treatments')->whereIn('invoice_id', $invoiceIds)->delete();
                }
                if (Schema::hasTable('invoice_other_charges')) {
                    DB::table('invoice_other_charges')->whereIn('invoice_id', $invoiceIds)->delete();
                }

                $counts['invoices'] = DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
            }

            DB::table('import_job_records')->where('import_job_id', $job->id)->delete();

            $job->update(['status' => 'undone', 'imported_count' => 0]);

            return $counts;
        });

        return ['deleted' => $deleted];
    }

    public function generateErrorReportCsv(ImportJob $job): string
    {
        $handle = fopen('php://temp', 'r+');

        // CSV Header
        fputcsv($handle, ['Row #', 'Source Column', 'Destination Field', 'Error Code', 'Error Message', 'Raw Data']);

        $job->errors()->orderBy('row_number', 'asc')->orderBy('id', 'asc')->chunk(200, function ($errors) use ($handle) {
            foreach ($errors as $err) {
                $rawData = $err->raw_data;
                $sanitizedRaw = $rawData;
                if (is_array($rawData)) {
                    $sanitizedRaw = array_map(function ($val) {
                        return is_string($val) ? $this->defendFormulaInjection($val) : $val;
                    }, $rawData);
                }

                $rawJson = is_array($sanitizedRaw) 
                    ? json_encode($sanitizedRaw, JSON_UNESCAPED_UNICODE) 
                    : (string)$sanitizedRaw;

                fputcsv($handle, [
                    $this->defendFormulaInjection((string)$err->row_number),
                    $this->defendFormulaInjection((string)($err->source_column ?? '')),
                    $this->defendFormulaInjection((string)($err->destination_field ?? '')),
                    $this->defendFormulaInjection((string)$err->error_code),
                    $this->defendFormulaInjection((string)$err->error_message),
                    $this->defendFormulaInjection($rawJson),
                ]);
            }
        });

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return $csvContent;
    }

    /**
     * Record the procedure from a patient import row as a medical history for that patient, dated
     * on the row's last visit (or today). Skipped when there is no procedure, or when the same
     * procedure is already recorded on that date, so re-importing the file does not duplicate it.
     */
    protected function createMedicalHistoryFromPatientRow(int $patientId, array $cleanData): void
    {
        $procedure = trim((string) ($cleanData['history'] ?? ''));
        if ($procedure === '' || !Schema::hasTable('medical_histories')) {
            return;
        }

        $date = !empty($cleanData['last_visit'])
            ? Carbon::parse($cleanData['last_visit'])->toDateString()
            : now()->toDateString();

        $alreadyRecorded = DB::table('medical_histories')
            ->where('patient_id', $patientId)
            ->whereDate('date', $date)
            ->where('treatment_given', $procedure)
            ->exists();
        if ($alreadyRecorded) {
            return;
        }

        $now = now();
        $record = [
            'patient_id' => $patientId,
            'date' => $date,
            // chief_complaint is required; the sheet only gives the procedure
            'chief_complaint' => 'Routine Consultation',
            'treatment_given' => $procedure,
            'consent' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('medical_histories', 'client_uuid')) {
            $record['client_uuid'] = (string) Str::uuid();
        }

        DB::table('medical_histories')->insert($record);
    }

    /**
     * Create an appointment for a patient's next visit date, the same way AutoScheduleNextVisits does.
     * Skipped when there is no date, the date is already past, or an appointment exists on that date.
     */
    protected function scheduleNextVisitAppointment(int $patientId, $nextVisit, ?int $branchId, ?string $jobId = null): void
    {
        if (empty($nextVisit)) {
            return;
        }

        $date = Carbon::parse((string)$nextVisit)->toDateString();
        if ($date < now()->toDateString()) {
            return;
        }

        $patient = DB::table('patients')->where('id', $patientId)->first();
        if (!$patient) {
            return;
        }

        $exists = DB::table('appointments')
            ->where('patient_id', $patientId)
            ->whereDate('date', $date)
            ->exists();
        if ($exists) {
            return;
        }

        $branch = $patient->branch_id ?? $branchId;
        $doctor = DB::table('employees')->where('branch_id', $branch)->where('status', 'Active')->first()
            ?? DB::table('employees')->where('status', 'Active')->first();

        $appointment = \App\Models\Appointment::create([
            'patient_name' => trim($patient->first_name . ' ' . $patient->last_name),
            'patient_id' => $patientId,
            'doctor_name' => $doctor->name ?? null,
            'doctor_id' => $doctor->id ?? null,
            'treatment' => 'Routine Checkup',
            'status' => 'Scheduled',
            'date' => $date,
            'time' => '09:00 AM',
            'branch_id' => $branch,
            'token_number' => 'T' . Str::upper(Str::random(6)),
        ]);

        $this->recordCreatedRecord($jobId, 'appointments', (int) $appointment->id);
    }

    /**
     * Remember a row this import job created, so the job can be undone later.
     */
    protected function recordCreatedRecord(?string $jobId, string $table, int $recordId): void
    {
        if (!$jobId) {
            return;
        }

        DB::table('import_job_records')->insert([
            'import_job_id' => $jobId,
            'table_name' => $table,
            'record_id' => $recordId,
            'action' => 'created',
            'created_at' => now(),
        ]);
    }

    /**
     * Update linked patient demographics (age, gender, address) if provided during medical history import.
     */
    protected function updatePatientDemographics(int $patientId, array $demographics): void
    {
        if (empty($demographics)) {
            return;
        }

        $patient = DB::table('patients')->where('id', $patientId)->first();
        if (!$patient) {
            return;
        }

        $updates = [];

        if (!empty($demographics['gender'])) {
            $updates['gender'] = $demographics['gender'];
        }

        if (!empty($demographics['age'])) {
            $updates['age'] = (int)$demographics['age'];
        }

        if (!empty($demographics['date_of_birth']) && empty($patient->date_of_birth)) {
            $updates['date_of_birth'] = $demographics['date_of_birth'];
        }

        if (!empty($demographics['address'])) {
            $updates['address'] = $demographics['address'];
        }

        if (!empty($updates)) {
            if (Schema::hasColumn('patients', 'updated_at')) {
                $updates['updated_at'] = now();
            }
            DB::table('patients')->where('id', $patientId)->update($updates);
        }
    }

    /**
     * Automatically create or update a linked invoice for a laboratory order.
     */
    protected function handleLabOrderInvoice(int $labOrderId, array $cleanData, array $data, ?int $branchId, Carbon $now): void
    {
        $totalAmount = isset($data['_total_amount']) ? (float)$data['_total_amount'] : (float)($cleanData['applied_price'] ?? 0);
        $paidAmount = isset($data['_paid_amount']) ? (float)$data['_paid_amount'] : 0.00;
        $balance = max(0, $totalAmount - $paidAmount);
        $invoiceStatus = ($balance > 0) ? 'Pending' : 'Paid';

        // Resolve client name for the invoice's patient_name field
        $clientName = $data['_resolved_client_name'] ?? null;
        if (empty($clientName)) {
            $clientId = $cleanData['client_id'] ?? DB::table('laboratory_orders')->where('id', $labOrderId)->value('client_id');
            if ($clientId) {
                $clientName = DB::table('clients')->where('id', $clientId)->value('name');
            }
        }
        if (empty($clientName)) {
            $clientName = 'Lab Client';
        }

        $orderBranchId = $cleanData['branch_id'] ?? $branchId ?? 1;
        if (!DB::table('branches')->where('id', $orderBranchId)->exists()) {
            $orderBranchId = (int)(DB::table('branches')->value('id') ?? 1);
        }

        $issueDate = !empty($cleanData['sent_date']) ? Carbon::parse($cleanData['sent_date'])->toDateString() : $now->toDateString();
        $dueDate = !empty($cleanData['due_date']) ? Carbon::parse($cleanData['due_date'])->toDateString() : Carbon::parse($issueDate)->addDays(30)->toDateString();

        // Check if laboratory order already has a linked invoice
        $existingInvoiceId = DB::table('laboratory_orders')->where('id', $labOrderId)->value('invoice_id');
        $existingInvoice = null;

        if ($existingInvoiceId) {
            $existingInvoice = DB::table('invoices')->where('id', $existingInvoiceId)->first();
        }

        // Also check if an invoice_number was provided that matches an existing invoice
        if (!$existingInvoice && !empty($cleanData['invoice_number'])) {
            $existingInvoice = DB::table('invoices')->where('receipt_number', $cleanData['invoice_number'])->first();
        }

        if ($existingInvoice) {
            $invoiceId = $existingInvoice->id;
            DB::table('invoices')->where('id', $invoiceId)->update([
                'patient_name' => $clientName,
                'amount' => $totalAmount,
                'patient_responsibility' => $totalAmount,
                'patient_paid_amount' => $paidAmount,
                'patient_balance' => $balance,
                'paid_amount' => $paidAmount,
                'status' => $invoiceStatus,
                'category' => 'lab-income',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'branch_id' => $orderBranchId,
                'updated_at' => $now,
            ]);
        } else {
            $receiptNumber = !empty($cleanData['invoice_number']) 
                ? $cleanData['invoice_number'] 
                : 'INV-LAB-' . strtoupper(Str::random(8));

            $invoiceId = DB::table('invoices')->insertGetId([
                'receipt_number' => $receiptNumber,
                'client_uuid' => (string) Str::uuid(),
                'patient_name' => $clientName,
                'patient_id' => null,
                'amount' => $totalAmount,
                'patient_responsibility' => $totalAmount,
                'patient_paid_amount' => $paidAmount,
                'patient_balance' => $balance,
                'insurance_responsibility' => 0.00,
                'insurance_paid_amount' => 0.00,
                'insurance_balance' => 0.00,
                'paid_amount' => $paidAmount,
                'status' => $invoiceStatus,
                'category' => 'lab-income',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'branch_id' => $orderBranchId,
                'payment_method' => 'Cash',
                'medicines' => json_encode(['lab_order_id' => $labOrderId]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Link the invoice back to the laboratory order
        $orderUpdates = ['invoice_id' => $invoiceId];
        if (empty($cleanData['invoice_number'])) {
            $receiptNum = DB::table('invoices')->where('id', $invoiceId)->value('receipt_number');
            $orderUpdates['invoice_number'] = $receiptNum ?: (string)$invoiceId;
        }
        DB::table('laboratory_orders')->where('id', $labOrderId)->update($orderUpdates);

        // Record line item in invoice_treatments if table exists
        if (Schema::hasTable('invoice_treatments')) {
            $hasTreatments = DB::table('invoice_treatments')->where('invoice_id', $invoiceId)->exists();
            if (!$hasTreatments) {
                DB::table('invoice_treatments')->insert([
                    'invoice_id' => $invoiceId,
                    'procedure' => $cleanData['item'] ?? 'Lab Order',
                    'procedure_id' => $cleanData['lab_service_id'] ?? null,
                    'cost' => $totalAmount,
                    'quantity' => 1,
                    'unit_price' => $totalAmount,
                    'total_amount' => $totalAmount,
                    'patient_amount' => $totalAmount,
                    'insurance_amount' => 0.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Escape spreadsheet formula injection prefixes (=, +, -, @).
     */
    public function defendFormulaInjection(string $val): string
    {
        if (preg_match('/^[=\+\-@]/', $val)) {
            return "'" . $val;
        }
        return $val;
    }
}


