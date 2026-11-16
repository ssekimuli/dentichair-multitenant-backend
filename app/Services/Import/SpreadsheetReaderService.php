<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SpreadsheetReaderService
{
    /**
     * Detect CSV delimiter by analyzing the first non-empty lines.
     */
    public function detectDelimiter(string $filePath): string
    {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return ',';
        }

        $delimiters = [',', ';', "\t", '|'];
        $counts = array_fill_keys($delimiters, 0);
        $linesAnalyzed = 0;

        while (($line = fgets($handle)) !== false && $linesAnalyzed < 5) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            foreach ($delimiters as $delimiter) {
                $counts[$delimiter] += substr_count($line, $delimiter);
            }
            $linesAnalyzed++;
        }
        fclose($handle);

        arsort($counts);
        $topDelimiter = array_key_first($counts);
        return ($counts[$topDelimiter] > 0) ? $topDelimiter : ',';
    }

    /**
     * Convert Excel file to CSV if needed and return path to CSV.
     * If the file is already CSV/TXT, returns the original path.
     * Clinic visit ledgers are rewritten into a flat patients layout (see ClinicLedgerNormalizer).
     * A returned path that differs from $filePath is a temp file the caller deletes.
     */
    public function ensureCsv(string $filePath, ?string $sheet = null, ?string $table = null): string
    {
        $csvPath = $this->convertToCsv($filePath, $sheet);

        $normalized = (new ClinicLedgerNormalizer())->normalize($csvPath, $this->detectDelimiter($csvPath), $table);
        if ($normalized === null) {
            return $csvPath;
        }

        if ($csvPath !== $filePath && is_file($csvPath)) {
            @unlink($csvPath);
        }

        return $normalized;
    }

    protected function convertToCsv(string $filePath, ?string $sheet = null): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'])) {
            return $filePath;
        }

        // Must be xlsx or xls
        // tempnam() creates a bare file; remove it and use a .csv name, then clean up the CSV at the end
        $tempBase = tempnam(sys_get_temp_dir(), 'univ_imp_');
        $tempCsvPath = $tempBase . '.csv';
        if ($tempBase !== false) {
            @unlink($tempBase);
        }
        register_shutdown_function(static function () use ($tempCsvPath) {
            if (is_file($tempCsvPath)) {
                @unlink($tempCsvPath);
            }
        });
        $pythonScript = base_path('app/Utils/excel_to_csv.py');
        $pythonBin = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';

        $args = [
            $pythonBin,
            escapeshellarg($pythonScript),
            escapeshellarg($filePath),
            escapeshellarg($tempCsvPath),
        ];

        if ($sheet) {
            $args[] = escapeshellarg('--sheet=' . $sheet);
        }

        $command = implode(' ', $args) . ' 2>&1';
        $output = [];
        $resultCode = 0;
        exec($command, $output, $resultCode);

        // Fallback for Linux environments where python3 vs python differs
        if ($resultCode !== 0) {
            $fallbackBin = $pythonBin === 'python3' ? 'python' : 'python3';
            $args[0] = $fallbackBin;
            $command = implode(' ', $args) . ' 2>&1';
            exec($command, $output, $resultCode);
        }

        if ($resultCode !== 0 || !file_exists($tempCsvPath) || filesize($tempCsvPath) === 0) {
            Log::error('Universal Import: Excel to CSV conversion failed', [
                'file' => $filePath,
                'result_code' => $resultCode,
                'output' => implode("\n", $output),
            ]);
            throw new \RuntimeException('Failed to parse Excel file. Ensure it is a valid .xlsx or .xls file.');
        }

        return $tempCsvPath;
    }

    /**
     * Get headers from file.
     */
    public function getHeaders(string $filePath, ?string $sheet = null, ?string $table = null): array
    {
        $csvPath = $this->ensureCsv($filePath, $sheet, $table);
        $delimiter = $this->detectDelimiter($csvPath);

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return [];
        }

        $headers = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (empty($row) || (count($row) === 1 && trim((string)$row[0]) === '')) {
                continue;
            }

            // Remove UTF-8 BOM if present on first item
            if (!empty($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }

            $headers = array_map(function ($h) {
                return trim((string)$h);
            }, $row);
            break;
        }

        fclose($handle);

        // Clean up temp CSV if created from Excel
        if ($csvPath !== $filePath && file_exists($csvPath)) {
            @unlink($csvPath);
        }

        return $headers;
    }

    /**
     * Get preview sample rows from file.
     */
    public function getPreviewRows(string $filePath, int $limit = 5, ?string $sheet = null, ?string $table = null): array
    {
        $csvPath = $this->ensureCsv($filePath, $sheet, $table);
        $delimiter = $this->detectDelimiter($csvPath);

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return [];
        }

        $headers = [];
        $sampleRows = [];

        // Read header
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (empty($row) || (count($row) === 1 && trim((string)$row[0]) === '')) {
                continue;
            }
            if (!empty($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }
            $headers = array_map('trim', $row);
            break;
        }

        if (empty($headers)) {
            fclose($handle);
            if ($csvPath !== $filePath && file_exists($csvPath)) {
                @unlink($csvPath);
            }
            return [];
        }

        // Read data rows
        $rowCount = 0;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $rowCount < $limit) {
            if (empty($row) || (count($row) === 1 && trim((string)$row[0]) === '')) {
                continue;
            }

            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }

            $rowSlice = array_slice($row, 0, count($headers));
            $mappedSample = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $mappedSample[$header] = isset($rowSlice[$index]) ? trim((string)$rowSlice[$index]) : null;
                }
            }

            $sampleRows[] = $mappedSample;
            $rowCount++;
        }

        fclose($handle);

        if ($csvPath !== $filePath && file_exists($csvPath)) {
            @unlink($csvPath);
        }

        return $sampleRows;
    }

    /**
     * Count total data rows in the file (excluding header and trailing empty rows).
     */
    public function countRows(string $filePath, ?string $sheet = null, ?string $table = null): int
    {
        $csvPath = $this->ensureCsv($filePath, $sheet, $table);
        $delimiter = $this->detectDelimiter($csvPath);

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            return 0;
        }

        $totalRows = 0;
        $isFirst = true;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (empty($row) || (count($row) === 1 && trim((string)$row[0]) === '')) {
                continue;
            }
            if ($isFirst) {
                $isFirst = false;
                continue; // skip header
            }
            $totalRows++;
        }

        fclose($handle);

        if ($csvPath !== $filePath && file_exists($csvPath)) {
            @unlink($csvPath);
        }

        return $totalRows;
    }

    /**
     * Process file in chunks, calling callback for each batch of rows.
     * Each row yielded to callback is an associative array: [row_number, headers, values_by_header].
     */
    public function processChunks(string $filePath, int $chunkSize, callable $callback, ?string $sheet = null, ?string $table = null): void
    {
        $csvPath = $this->ensureCsv($filePath, $sheet, $table);
        $delimiter = $this->detectDelimiter($csvPath);

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Unable to open file for processing: {$filePath}");
        }

        $headers = [];
        $isFirst = true;
        $chunk = [];
        $spreadsheetRowNumber = 1; // 1-indexed for human error reporting

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (empty($row) || (count($row) === 1 && trim((string)$row[0]) === '')) {
                $spreadsheetRowNumber++;
                continue;
            }

            if ($isFirst) {
                if (!empty($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
                }
                $headers = array_map('trim', $row);
                $isFirst = false;
                $spreadsheetRowNumber++;
                continue;
            }

            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }

            $rowSlice = array_slice($row, 0, count($headers));
            $rowData = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $rowData[$header] = isset($rowSlice[$index]) ? trim((string)$rowSlice[$index]) : null;
                }
            }

            $chunk[] = [
                'row_number' => $spreadsheetRowNumber,
                'data' => $rowData,
            ];

            if (count($chunk) >= $chunkSize) {
                $callback($chunk, $headers);
                $chunk = [];
            }

            $spreadsheetRowNumber++;
        }

        if (!empty($chunk)) {
            $callback($chunk, $headers);
        }

        fclose($handle);

        if ($csvPath !== $filePath && file_exists($csvPath)) {
            @unlink($csvPath);
        }
    }
}
