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

class UniversalImportService
{
    protected SpreadsheetReaderService $reader;
    protected ImportSchemaService $schemaService;
    protected ImportMappingService $mappingService;
    protected ImportValidationService $validationService;

    public function __construct(
        SpreadsheetReaderService $reader,
        ImportSchemaService $schemaService,
        ImportMappingService $mappingService,
        ImportValidationService $validationService
    ) {
        $this->reader = $reader;
        $this->schemaService = $schemaService;
        $this->mappingService = $mappingService;
        $this->validationService = $validationService;
    }

    /**
     * Generate preview from uploaded spreadsheet.
     */
    public function generatePreview(UploadedFile $file, string $table, ?string $sheet = null, $user = null): array
    {
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
        $storedPath = $file->store($tempDir, $tempDisk);
        $fullPath = Storage::disk($tempDisk)->path($storedPath);

        // Inspect headers and sample rows
        $headers = $this->reader->getHeaders($fullPath, $sheet);
        if (empty($headers)) {
            Storage::disk($tempDisk)->delete($storedPath);
            throw new \InvalidArgumentException('Unable to parse file headers. The file may be empty or corrupted.');
        }

        $totalRows = $this->reader->countRows($fullPath, $sheet);
        $sampleRows = $this->reader->getPreviewRows($fullPath, 5, $sheet);
        $analysis = $this->mappingService->proposeMapping($headers, $table);

        // Create pending import job record
        $job = ImportJob::create([
            'user_id' => $user?->id,
            'branch_id' => $user?->branch_id,
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
        $table = $params['table'];

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

        // Either reuse preview job or create a new one from uploaded file
        if (!empty($params['import_id'])) {
            $importJob = ImportJob::where('id', $params['import_id'])->first();
            if (!$importJob) {
                throw new \InvalidArgumentException("Import job '{$params['import_id']}' not found.");
            }
            if ($importJob->table_name !== $table) {
                throw new \InvalidArgumentException("Import job table does not match provided table.");
            }
        } elseif ($file) {
            $tempDir = Config::get('import.temp_directory', 'imports');
            $storedPath = $file->store($tempDir, $tempDisk);
            $fullPath = Storage::disk($tempDisk)->path($storedPath);
            $totalRows = $this->reader->countRows($fullPath, $params['sheet'] ?? null);

            $importJob = ImportJob::create([
                'user_id' => $user?->id,
                'branch_id' => $user?->branch_id,
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

        $table = $job->table_name;
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

                        // Normalize row
                        $normalized = $this->validationService->normalizeRow($mappedData, $table, $user);

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
                $job->sheet_name
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

            $job->update([
                'status' => 'failed',
                'error_summary' => 'Import execution failed: ' . $e->getMessage(),
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
                    if ($mode === 'insert') {
                        if ($hasTimestamps) {
                            $data['created_at'] = $now;
                            $data['updated_at'] = $now;
                        }
                        DB::table($table)->insert($data);
                        $importedCount++;
                        continue;
                    }

                    // Modes requiring unique key match: skip_duplicates, update_existing, upsert
                    $keyValue = $data[$updateKey] ?? null;

                    if ($keyValue === null || $keyValue === '') {
                        // Cannot match without update key value
                        if ($mode === 'skip_duplicates') {
                            if ($hasTimestamps) {
                                $data['created_at'] = $now;
                                $data['updated_at'] = $now;
                            }
                            DB::table($table)->insert($data);
                            $importedCount++;
                        } else {
                            ImportRowError::create([
                                'import_job_id' => $jobId,
                                'row_number' => $rowNumber,
                                'source_column' => $fieldToHeaderMap[$updateKey] ?? $updateKey,
                                'destination_field' => $updateKey,
                                'error_code' => 'MISSING_UNIQUE_KEY',
                                'error_message' => "Value for unique key '{$updateKey}' is required for mode '{$mode}'.",
                                'raw_data' => $rawData,
                            ]);
                            $failedCount++;
                        }
                        continue;
                    }

                    $query = DB::table($table)->where($updateKey, $keyValue);
                    if ($tenantColumn && $branchId !== null && Schema::hasColumn($table, $tenantColumn)) {
                        $query->where($tenantColumn, $branchId);
                    }

                    $existing = $query->first();

                    if ($existing) {
                        if ($mode === 'skip_duplicates') {
                            $skippedCount++;
                        } elseif ($mode === 'update_existing' || $mode === 'upsert') {
                            if ($hasTimestamps) {
                                $data['updated_at'] = $now;
                            }
                            // Don't overwrite created_at on update
                            unset($data['created_at']);
                            $query->update($data);
                            $updatedCount++;
                        }
                    } else {
                        if ($mode === 'update_existing') {
                            $skippedCount++;
                        } else { // insert or upsert
                            if ($hasTimestamps) {
                                $data['created_at'] = $now;
                                $data['updated_at'] = $now;
                            }
                            DB::table($table)->insert($data);
                            $importedCount++;
                        }
                    }
                } catch (\Throwable $e) {
                    if ($errorMode === 'atomic') {
                        $hasAtomicFailure = true;
                        $atomicFailureMessage = "Row {$rowNumber} database error: " . $e->getMessage();
                        throw $e;
                    }

                    ImportRowError::create([
                        'import_job_id' => $jobId,
                        'row_number' => $rowNumber,
                        'source_column' => null,
                        'destination_field' => null,
                        'error_code' => 'DATABASE_ERROR',
                        'error_message' => 'Database constraint violation or error: ' . $e->getMessage(),
                        'raw_data' => $rawData,
                    ]);
                    $failedCount++;
                }
            }
        });
    }

    /**
     * Generate CSV content with formula injection defense for downloading error report.
     */
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
     * Formula injection defense: prepends a single quote if string begins with =, +, -, @, \t, or \r.
     */
    public function defendFormulaInjection(string $val): string
    {
        if (preg_match('/^[=+\-@\t\r]/', $val)) {
            return "'" . $val;
        }
        return $val;
    }
}
