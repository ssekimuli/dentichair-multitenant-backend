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
                            if (isset($tableDefaults[$preserveCol]) && $data[$preserveCol] === $tableDefaults[$preserveCol]) {
                                $header = $fieldToHeaderMap[$preserveCol] ?? null;
                                if ($header === null || !isset($rawData[$header]) || trim((string)$rawData[$header]) === '') {
                                    unset($data[$preserveCol]);
                                }
                            }
                        }

                        DB::table($table)->where('id', $existing->id)->update($data);
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

                    DB::table($table)->insert($data);
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
                            DB::table($table)->where('id', $duplicateExisting->id)->update($data);
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
        // 1. Explicit update_key match if configured and present in $data
        if (!empty($updateKey) && !empty($data[$updateKey]) && Schema::hasColumn($table, $updateKey)) {
            if (in_array($updateKey, ['phone', 'contact', 'telephone', 'mobile'], true)) {
                $found = $this->findRecordByPhone($table, (string)$data[$updateKey], $updateKey, $tenantColumn, $branchId);
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
            $found = $this->findRecordByPhone($table, (string)$phoneVal, $phoneCol, $tenantColumn, $branchId);
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
     * Find record by contact/phone with international & local format resilience.
     */
    protected function findRecordByPhone(
        string $table,
        string $phoneVal,
        string $phoneCol,
        ?string $tenantColumn,
        ?int $branchId
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
            $branchMatch = $buildPhoneQuery()->where($tenantColumn, $branchId)->first();
            if ($branchMatch) {
                return $branchMatch;
            }
        }

        // Try globally across table
        return $buildPhoneQuery()->first();
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
