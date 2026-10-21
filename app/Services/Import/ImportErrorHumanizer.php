<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

class ImportErrorHumanizer
{
    protected ?ImportSchemaService $schemaService;

    public function __construct(?ImportSchemaService $schemaService = null)
    {
        $this->schemaService = $schemaService;
    }

    /**
     * Humanize any database or execution error into clear, non-technical clinic guidance.
     *
     * @param \Throwable|string $exception
     * @param string $table
     * @param array $rowData
     * @param array $fieldToHeaderMap
     * @return array{error_code: string, error_message: string, destination_field: ?string, source_column: ?string}
     */
    public function humanize(
        \Throwable|string $exception,
        string $table,
        array $rowData = [],
        array $fieldToHeaderMap = []
    ): array {
        $rawMessage = is_string($exception) ? $exception : $exception->getMessage();

        // 1. DUPLICATE KEY / UNIQUE CONSTRAINT
        if ($this->isDuplicateEntryError($rawMessage)) {
            return $this->handleDuplicateEntry($rawMessage, $table, $rowData, $fieldToHeaderMap);
        }

        // 2. FOREIGN KEY CONSTRAINT
        if ($this->isForeignKeyError($rawMessage)) {
            return $this->handleForeignKeyError($rawMessage, $table, $rowData, $fieldToHeaderMap);
        }

        // 3. NOT NULL CONSTRAINT
        if ($this->isNotNullError($rawMessage)) {
            return $this->handleNotNullError($rawMessage, $table, $rowData, $fieldToHeaderMap);
        }

        // 4. DATA TOO LONG / TRUNCATION
        if ($this->isDataTooLongError($rawMessage)) {
            return $this->handleDataTooLongError($rawMessage, $table, $rowData, $fieldToHeaderMap);
        }

        // 5. INCORRECT DATA FORMAT (DATE OR NUMBER)
        if ($this->isDataFormatError($rawMessage)) {
            return $this->handleDataFormatError($rawMessage, $table, $rowData, $fieldToHeaderMap);
        }

        // 6. FALLBACK: GENERAL DATABASE ERROR (STRIP RAW SQL & CREDENTIALS)
        return $this->handleGenericDatabaseError($rawMessage, $table);
    }

    /**
     * Clean summary for top-level job errors.
     */
    public function humanizeSummary(\Throwable|string $exception): string
    {
        $msg = is_string($exception) ? $exception : $exception->getMessage();

        // Check if message already humanized
        if (str_starts_with($msg, 'Import stopped at') || str_starts_with($msg, 'Duplicate Record:') || str_starts_with($msg, 'Missing Required')) {
            return $msg;
        }

        // Strip SQL, connection info, IPs, ports
        $cleaned = preg_replace('/\(SQL:.*?\)/is', '', $msg);
        $cleaned = preg_replace('/\(Connection:.*?\)/is', '', $cleaned);
        $cleaned = preg_replace('/SQLSTATE\[.*?\]:\s*/is', '', $cleaned);
        $cleaned = preg_replace('/Integrity constraint violation:\s*/is', '', $cleaned);
        $cleaned = trim($cleaned);

        if ($this->isDuplicateEntryError($msg)) {
            if (preg_match("/Duplicate entry '(.*?)' for key '(?:.*?\.)?(.*?)'/i", $msg, $m)) {
                $val = $m[1] ?? '';
                $key = $this->cleanKeyName($m[2] ?? '');
                $keyLabel = Str::headline($key);
                return "Duplicate record found: {$keyLabel} '{$val}' is already registered in the system.";
            }
            return 'A record with duplicate unique details already exists in the system.';
        }

        if (empty($cleaned)) {
            return 'The import encountered a database constraint. Please verify your spreadsheet columns and try again.';
        }

        return Str::limit($cleaned, 250);
    }

    /**
     * Detect duplicate entry errors.
     */
    public function isDuplicateEntryError(string $message): bool
    {
        return str_contains($message, '1062 Duplicate entry')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'duplicate key value violates unique constraint');
    }

    /**
     * Extract duplicate value and field name from error message.
     */
    public function extractDuplicateInfo(string $message, string $table = ''): array
    {
        $duplicateValue = null;
        $keyName = null;

        if (preg_match("/Duplicate entry '(.*?)' for key '(?:.*?\.)?(.*?)'/i", $message, $m)) {
            $duplicateValue = $m[1] ?? null;
            $keyName = $m[2] ?? null;
        } elseif (preg_match('/UNIQUE constraint failed: (?:.*?\.)?(.*?)$/i', $message, $m)) {
            $keyName = $m[1] ?? null;
        } elseif (preg_match('/Key \((.*?)\)=\((.*?)\) already exists/i', $message, $m)) {
            $keyName = $m[1] ?? null;
            $duplicateValue = $m[2] ?? null;
        }

        $field = $keyName ? $this->cleanKeyName($keyName, $table) : null;

        return [
            'value' => $duplicateValue,
            'field' => $field,
        ];
    }

    /**
     * Handle duplicate entry with intelligent field and value extraction.
     */
    protected function handleDuplicateEntry(
        string $message,
        string $table,
        array $rowData,
        array $fieldToHeaderMap
    ): array {
        $duplicateValue = null;
        $keyName = null;

        // MySQL pattern: Duplicate entry 'val' for key 'table.key_name'
        if (preg_match("/Duplicate entry '(.*?)' for key '(?:.*?\.)?(.*?)'/i", $message, $m)) {
            $duplicateValue = $m[1] ?? null;
            $keyName = $m[2] ?? null;
        } elseif (preg_match('/UNIQUE constraint failed: (?:.*?\.)?(.*?)$/i', $message, $m)) {
            $keyName = $m[1] ?? null;
        } elseif (preg_match('/Key \((.*?)\)=\((.*?)\) already exists/i', $message, $m)) {
            $keyName = $m[1] ?? null;
            $duplicateValue = $m[2] ?? null;
        }

        // Determine destination field:
        // Priority 1: Find field in rowData that exactly matches $duplicateValue
        $destinationField = null;
        if (!empty($duplicateValue) && !empty($rowData)) {
            foreach ($rowData as $f => $val) {
                if (is_scalar($val) && (string)$val === (string)$duplicateValue) {
                    $destinationField = $f;
                    break;
                }
            }
        }

        // Priority 2: Extract from key name (e.g. patients_email_unique -> email)
        if (!$destinationField && !empty($keyName)) {
            $destinationField = $this->cleanKeyName($keyName, $table);
        }

        // Priority 3: Fallback
        if (!$destinationField) {
            $destinationField = 'record';
        }

        $fieldLabel = $this->getFieldLabel($table, $destinationField);
        $entityLabel = $this->getEntityLabel($table);
        $sourceColumn = $fieldToHeaderMap[$destinationField] ?? null;

        $valText = !empty($duplicateValue) ? " '{$duplicateValue}'" : '';

        $explanation = "A {$entityLabel} with {$fieldLabel}{$valText} already exists in the system. "
            . "To update this record instead of creating a new one, choose 'Update Existing' or 'Upsert' mode before importing.";

        return [
            'error_code' => 'DUPLICATE_RECORD',
            'error_message' => $explanation,
            'destination_field' => ($destinationField !== 'record') ? $destinationField : null,
            'source_column' => $sourceColumn,
        ];
    }

    /**
     * Detect foreign key errors.
     */
    protected function isForeignKeyError(string $message): bool
    {
        return str_contains($message, 'foreign key constraint fails')
            || str_contains($message, 'Cannot add or update a child row')
            || str_contains($message, 'FOREIGN KEY constraint failed')
            || str_contains($message, 'violates foreign key constraint');
    }

    /**
     * Handle foreign key errors.
     */
    protected function handleForeignKeyError(
        string $message,
        string $table,
        array $rowData,
        array $fieldToHeaderMap
    ): array {
        $field = null;
        $parentTable = null;

        if (preg_match('/FOREIGN KEY \([`\'"]?(.*?)[`\'"]?\) REFERENCES [`\'"]?(.*?)[`\'"]?/i', $message, $m)) {
            $field = $m[1] ?? null;
            $parentTable = $m[2] ?? null;
        } elseif (preg_match('/CONSTRAINT [`\'"]?(.*?)[`\'"]? FOREIGN KEY/i', $message, $m)) {
            $constraint = $m[1] ?? '';
            $field = $this->cleanKeyName($constraint, $table);
        }

        $fieldLabel = $field ? $this->getFieldLabel($table, $field) : 'Referenced item';
        $parentLabel = $parentTable ? $this->getEntityLabel($parentTable) : 'system record';
        $val = $field && isset($rowData[$field]) ? $rowData[$field] : null;
        $valText = ($val !== null && $val !== '') ? " (ID: '{$val}')" : '';
        $sourceColumn = $field ? ($fieldToHeaderMap[$field] ?? null) : null;

        $explanation = "The selected {$fieldLabel}{$valText} does not exist in the system. "
            . "Please make sure this {$parentLabel} exists before importing, or leave the column blank if optional.";

        return [
            'error_code' => 'INVALID_REFERENCE',
            'error_message' => $explanation,
            'destination_field' => $field,
            'source_column' => $sourceColumn,
        ];
    }

    /**
     * Detect NOT NULL errors.
     */
    protected function isNotNullError(string $message): bool
    {
        return str_contains($message, 'cannot be null')
            || str_contains($message, "doesn't have a default value")
            || str_contains($message, 'NOT NULL constraint failed');
    }

    /**
     * Handle NOT NULL errors.
     */
    protected function handleNotNullError(
        string $message,
        string $table,
        array $rowData,
        array $fieldToHeaderMap
    ): array {
        $field = null;

        if (preg_match("/Column '(.*?)' cannot be null/i", $message, $m)) {
            $field = $m[1] ?? null;
        } elseif (preg_match("/Field '(.*?)' doesn't have a default value/i", $message, $m)) {
            $field = $m[1] ?? null;
        } elseif (preg_match('/NOT NULL constraint failed: (?:.*?\.)?(.*?)$/i', $message, $m)) {
            $field = $m[1] ?? null;
        }

        $fieldLabel = $field ? $this->getFieldLabel($table, $field) : 'Required field';
        $sourceColumn = $field ? ($fieldToHeaderMap[$field] ?? null) : null;
        $colText = $sourceColumn ? " in spreadsheet column '{$sourceColumn}'" : '';

        $explanation = "The field '{$fieldLabel}' is required and cannot be blank. Please enter a value{$colText}.";

        return [
            'error_code' => 'REQUIRED_FIELD_EMPTY',
            'error_message' => $explanation,
            'destination_field' => $field,
            'source_column' => $sourceColumn,
        ];
    }

    /**
     * Detect data too long errors.
     */
    protected function isDataTooLongError(string $message): bool
    {
        return str_contains($message, 'Data too long for column')
            || str_contains($message, 'value too long for type');
    }

    /**
     * Handle data too long errors.
     */
    protected function handleDataTooLongError(
        string $message,
        string $table,
        array $rowData,
        array $fieldToHeaderMap
    ): array {
        $field = null;

        if (preg_match("/Data too long for column '(.*?)'/i", $message, $m)) {
            $field = $m[1] ?? null;
        }

        $fieldLabel = $field ? $this->getFieldLabel($table, $field) : 'Field';
        $sourceColumn = $field ? ($fieldToHeaderMap[$field] ?? null) : null;

        $explanation = "The information entered for '{$fieldLabel}' exceeds the maximum allowed characters. Please shorten it in your spreadsheet.";

        return [
            'error_code' => 'VALUE_TOO_LONG',
            'error_message' => $explanation,
            'destination_field' => $field,
            'source_column' => $sourceColumn,
        ];
    }

    /**
     * Detect format errors (date / numeric).
     */
    protected function isDataFormatError(string $message): bool
    {
        return str_contains($message, 'Incorrect date value')
            || str_contains($message, 'Incorrect datetime value')
            || str_contains($message, 'Incorrect integer value')
            || str_contains($message, 'Incorrect decimal value')
            || str_contains($message, 'invalid input syntax for type');
    }

    /**
     * Handle data format errors.
     */
    protected function handleDataFormatError(
        string $message,
        string $table,
        array $rowData,
        array $fieldToHeaderMap
    ): array {
        $field = null;
        $val = null;
        $isDate = str_contains($message, 'date');

        if (preg_match("/Incorrect (?:date|datetime|time|integer|decimal) value: '(.*?)' for column '(.*?)'/i", $message, $m)) {
            $val = $m[1] ?? null;
            $field = $m[2] ?? null;
        }

        $fieldLabel = $field ? $this->getFieldLabel($table, $field) : 'Field';
        $sourceColumn = $field ? ($fieldToHeaderMap[$field] ?? null) : null;
        $valDisplay = $val ? " (found: '{$val}')" : '';

        if ($isDate) {
            $explanation = "The date for '{$fieldLabel}'{$valDisplay} is invalid. Please format it as YYYY-MM-DD (e.g. 2026-05-15).";
        } else {
            $explanation = "The value for '{$fieldLabel}'{$valDisplay} must be a number without letters or symbols.";
        }

        return [
            'error_code' => 'INVALID_FORMAT',
            'error_message' => $explanation,
            'destination_field' => $field,
            'source_column' => $sourceColumn,
        ];
    }

    /**
     * Handle generic database errors safely.
     */
    protected function handleGenericDatabaseError(string $message, string $table): array
    {
        // Strip SQL, connections, hosts, credentials
        $cleaned = preg_replace('/\(SQL:.*?\)/is', '', $message);
        $cleaned = preg_replace('/\(Connection:.*?\)/is', '', $cleaned);
        $cleaned = preg_replace('/SQLSTATE\[.*?\]:\s*/is', '', $cleaned);
        $cleaned = preg_replace('/Integrity constraint violation:\s*/is', '', $cleaned);
        $cleaned = trim($cleaned);

        $explanation = 'This row could not be saved due to a database constraint. Please check your spreadsheet values.';
        if (!empty($cleaned) && strlen($cleaned) < 180 && !str_contains($cleaned, 'select') && !str_contains($cleaned, 'insert')) {
            $explanation = 'Database error: ' . $cleaned;
        }

        return [
            'error_code' => 'DATABASE_CONSTRAINT',
            'error_message' => $explanation,
            'destination_field' => null,
            'source_column' => null,
        ];
    }

    /**
     * Strip prefixes and suffixes from index/key names.
     */
    protected function cleanKeyName(string $keyName, string $table = ''): string
    {
        $k = strtolower($keyName);

        if ($k === 'primary') {
            return 'id';
        }

        // Remove table prefix if present e.g. "patients_email_unique" -> "email_unique"
        if (!empty($table) && str_starts_with($k, strtolower($table) . '_')) {
            $k = substr($k, strlen($table) + 1);
        }

        // Remove suffixes
        $k = preg_replace('/(_unique|_foreign|_index|_idx)$/i', '', $k);

        return $k;
    }

    /**
     * Friendly label for column.
     */
    protected function getFieldLabel(string $table, string $field): string
    {
        if ($this->schemaService) {
            $columns = $this->schemaService->getImportableColumns($table);
            if (isset($columns[$field]['label'])) {
                return $columns[$field]['label'];
            }
        }

        return Str::headline($field);
    }

    /**
     * Friendly singular label for entity table.
     */
    protected function getEntityLabel(string $table): string
    {
        return match ($table) {
            'patients' => 'patient',
            'appointments' => 'appointment',
            'employees' => 'employee / staff',
            'treatments' => 'treatment',
            'invoices' => 'invoice',
            'payments' => 'payment',
            'prescriptions' => 'prescription',
            'medicines' => 'medicine item',
            'inventory' => 'inventory item',
            'lab_orders' => 'lab order',
            'branches' => 'branch',
            default => Str::singular($table),
        };
    }
}
