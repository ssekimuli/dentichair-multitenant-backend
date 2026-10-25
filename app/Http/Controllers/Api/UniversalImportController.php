<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ImportExecuteRequest;
use App\Http\Requests\Import\ImportPreviewRequest;
use App\Models\ImportJob;
use App\Services\Import\ImportSchemaService;
use App\Services\Import\UniversalImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UniversalImportController extends Controller
{
    protected UniversalImportService $importService;
    protected ImportSchemaService $schemaService;

    public function __construct(
        UniversalImportService $importService,
        ImportSchemaService $schemaService
    ) {
        $this->importService = $importService;
        $this->schemaService = $schemaService;
    }

    /**
     * List all available destination tables authorized for the current user.
     *
     * GET /api/import/tables
     */
    public function tables(Request $request): JsonResponse
    {
        $user = $request->user();
        $tables = $this->schemaService->getAuthorizedTables($user);

        return response()->json([
            'success' => true,
            'message' => 'Authorized import tables retrieved successfully.',
            'data' => $tables,
        ]);
    }

    /**
     * List all available clinic branches for branch selection.
     *
     * GET /api/import/branches
     */
    public function branches(Request $request): JsonResponse
    {
        $branches = \App\Models\Branch::select(['id', 'name', 'address'])->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'message' => 'Clinic branches retrieved successfully.',
            'data' => $branches,
        ]);
    }

    /**
     * Get destination table schema and importable columns.
     *
     * GET /api/import/tables/{table}/columns
     */
    public function columns(Request $request, string $table): JsonResponse
    {
        $table = $this->schemaService->normalizeTableName($table);
        $user = $request->user();

        if (!$this->schemaService->isTableAuthorized($table, $user)) {
            return response()->json([
                'success' => false,
                'message' => "Access to table '{$table}' is forbidden or unauthorized.",
            ], 403);
        }

        $columns = $this->schemaService->getImportableColumns($table);
        $config = $this->schemaService->getTableConfig($table);

        return response()->json([
            'success' => true,
            'message' => 'Table schema retrieved successfully.',
            'data' => [
                'table' => $table,
                'label' => $config['label'] ?? $table,
                'description' => $config['description'] ?? '',
                'tenant_column' => $config['tenant_column'] ?? null,
                'default_unique_by' => $config['default_unique_by'] ?? 'id',
                'supported_unique_keys' => $config['unique_by'] ?? ['id'],
                'columns' => array_values($columns),
            ],
        ]);
    }

    /**
     * Upload and preview a spreadsheet import.
     *
     * POST /api/import/preview
     */
    public function preview(ImportPreviewRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $file = $request->file('file');
            $table = $request->input('table');
            $sheet = $request->input('sheet');
            $branchId = $request->input('branch_id');

            $previewData = $this->importService->generatePreview(
                $file,
                $table,
                $sheet,
                $user,
                $branchId ? (int)$branchId : null
            );

            return response()->json([
                'success' => true,
                'message' => 'Import preview generated.',
                'data' => $previewData,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while generating the preview: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute an import.
     *
     * POST /api/import/execute
     */
    public function execute(ImportExecuteRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $params = $request->validated();
            $file = $request->file('file');

            $job = $this->importService->executeImport($params, $user, $file);

            $statusCode = ($job->status === 'queued' || $job->status === 'processing') ? 202 : 200;
            if ($statusCode === 202) {
                $message = 'Import task has been queued for background processing.';
            } else {
                if ($job->status === 'failed') {
                    $message = $job->error_summary ?: 'Import could not be completed.';
                } elseif ($job->failed_count > 0 && $job->imported_count > 0) {
                    $message = "Import finished with partial success: {$job->imported_count} records imported, {$job->failed_count} rows had issues.";
                } elseif ($job->failed_count > 0 && $job->imported_count === 0) {
                    $message = "Import finished: No records were imported. {$job->failed_count} rows encountered duplicate or validation issues.";
                } else {
                    $message = "Import completed successfully! {$job->imported_count} records imported.";
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'job_id' => $job->id,
                    'table' => $job->table_name,
                    'status' => $job->status,
                    'mode' => $job->mode,
                    'total_rows' => $job->total_rows,
                    'processed_rows' => $job->processed_rows,
                    'imported' => $job->imported_count,
                    'updated' => $job->updated_count,
                    'skipped' => $job->skipped_count,
                    'failed' => $job->failed_count,
                    'progress_percentage' => $job->progressPercentage(),
                    'started_at' => $job->started_at?->toIso8601String(),
                    'completed_at' => $job->completed_at?->toIso8601String(),
                    'errors_url' => ($job->failed_count > 0) ? url("/api/import/jobs/{$job->id}/errors/download") : null,
                ],
            ], $statusCode);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            $humanizer = app(\App\Services\Import\ImportErrorHumanizer::class);
            return response()->json([
                'success' => false,
                'message' => 'Unable to complete import: ' . $humanizer->humanizeSummary($e),
            ], 500);
        }
    }

    /**
     * Get import job status and progress.
     *
     * GET /api/import/jobs/{id}
     */
    public function jobStatus(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $job = ImportJob::find($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => "Import job '{$id}' not found.",
            ], 404);
        }

        // Authorization check: ensure user or branch owns this job
        if ($user && $job->user_id && $job->user_id !== $user->id && $user->branch_id && $job->branch_id !== $user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this import job.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Import job status retrieved.',
            'data' => [
                'job_id' => $job->id,
                'table' => $job->table_name,
                'status' => $job->status,
                'mode' => $job->mode,
                'error_mode' => $job->error_mode,
                'total_rows' => $job->total_rows,
                'processed_rows' => $job->processed_rows,
                'imported' => $job->imported_count,
                'updated' => $job->updated_count,
                'skipped' => $job->skipped_count,
                'failed' => $job->failed_count,
                'progress_percentage' => $job->progressPercentage(),
                'started_at' => $job->started_at?->toIso8601String(),
                'completed_at' => $job->completed_at?->toIso8601String(),
                'error_summary' => $job->error_summary,
                'errors_url' => ($job->failed_count > 0) ? url("/api/import/jobs/{$job->id}/errors/download") : null,
            ],
        ]);
    }

    /**
     * Get paginated row-level errors for an import job.
     *
     * GET /api/import/jobs/{id}/errors
     */
    public function jobErrors(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $job = ImportJob::find($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => "Import job '{$id}' not found.",
            ], 404);
        }

        if ($user && $job->user_id && $job->user_id !== $user->id && $user->branch_id && $job->branch_id !== $user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this import job errors.',
            ], 403);
        }

        $perPage = min(100, max(1, (int)$request->query('per_page', 25)));
        $errors = $job->errors()->orderBy('row_number', 'asc')->orderBy('id', 'asc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Import row errors retrieved.',
            'data' => $errors,
        ]);
    }

    /**
     * Download error report as a CSV file with formula injection protection.
     *
     * GET /api/import/jobs/{id}/errors/download
     */
    public function downloadErrors(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        $job = ImportJob::find($id);

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => "Import job '{$id}' not found.",
            ], 404);
        }

        if ($user && $job->user_id && $job->user_id !== $user->id && $user->branch_id && $job->branch_id !== $user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this import job error report.',
            ], 403);
        }

        $fileName = "import_errors_{$job->table_name}_{$job->id}.csv";
        $csvContent = $this->importService->generateErrorReportCsv($job);

        return response()->streamDownload(function () use ($csvContent) {
            echo $csvContent;
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * Get paginated import history for current user/branch.
     *
     * GET /api/import/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ImportJob::query()->orderBy('created_at', 'desc');

        if ($user) {
            if ($user->branch_id) {
                $query->where('branch_id', $user->branch_id);
            } else {
                $query->where('user_id', $user->id);
            }
        }

        if ($table = $request->query('table')) {
            $query->where('table_name', $table);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min(100, max(1, (int)$request->query('per_page', 15)));
        $history = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Import history retrieved.',
            'data' => $history,
        ]);
    }
}
