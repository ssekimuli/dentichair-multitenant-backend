<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImportSchemaService
{
    /**
     * Get list of authorized import tables for the authenticated user.
     */
    public function getAuthorizedTables($user = null): array
    {
        $configuredTables = Config::get('import.tables', []);
        $authorized = [];

        foreach ($configuredTables as $tableIdentifier => $tableConfig) {
            if (!$this->isTableAuthorized($tableIdentifier, $user)) {
                continue;
            }

            // Verify table actually exists in database
            if (!Schema::hasTable($tableIdentifier)) {
                continue;
            }

            $columns = $this->getImportableColumns($tableIdentifier);

            $authorized[] = [
                'table' => $tableIdentifier,
                'label' => $tableConfig['label'] ?? Str::headline($tableIdentifier),
                'description' => $tableConfig['description'] ?? '',
                'tenant_column' => $tableConfig['tenant_column'] ?? null,
                'default_unique_by' => $tableConfig['default_unique_by'] ?? 'id',
                'supported_unique_keys' => $tableConfig['unique_by'] ?? ['id'],
                'supported_operations' => ['insert', 'skip_duplicates', 'update_existing', 'upsert'],
                'available_columns_count' => count($columns),
            ];
        }

        return $authorized;
    }

    /**
     * Normalize table identifiers (e.g. medical_history -> medical_histories).
     */
    public function normalizeTableName(string $table): string
    {
        $table = strtolower(trim($table));
        if ($table === 'medical_history') {
            return 'medical_histories';
        }
        if ($table === 'invoice') {
            return 'invoices';
        }
        if (in_array($table, ['lab_order', 'lab_orders', 'laboratory_order'], true)) {
            return 'laboratory_orders';
        }
        return $table;
    }

    /**
     * Check if user is authorized to access destination table.
     */
    public function isTableAuthorized(string $table, $user = null): bool
    {
        $table = $this->normalizeTableName($table);
        $configuredTables = Config::get('import.tables', []);

        if (!isset($configuredTables[$table])) {
            return false;
        }

        $tableConfig = $configuredTables[$table];
        $permission = $tableConfig['permission'] ?? null;

        if (!$permission) {
            return true;
        }

        if (!$user) {
            return false;
        }

        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($permission);
        }

        return true;
    }

    /**
     * Get configuration array for a specific table.
     */
    public function getTableConfig(string $table): ?array
    {
        $table = $this->normalizeTableName($table);
        return Config::get("import.tables.{$table}");
    }

    /**
     * Discover table schema and return full column metadata for importable columns.
     */
    public function getImportableColumns(string $table): array
    {
        $table = $this->normalizeTableName($table);
        $cacheKey = "universal_import_columns_{$table}";

        return Cache::remember($cacheKey, 3600, function () use ($table) {
            return $this->discoverColumns($table);
        });
    }

    /**
     * Dynamically discover columns using Laravel Schema API and DB introspection.
     */
    protected function discoverColumns(string $table): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $tableConfig = $this->getTableConfig($table) ?? [];
        $systemExcluded = Config::get('import.system_excluded_columns', []);
        $tableExcluded = $tableConfig['excluded_columns'] ?? [];
        $allExcluded = array_merge($systemExcluded, $tableExcluded);

        // Discovered indexes & unique keys
        $indexes = Schema::getIndexes($table);
        $uniqueColumns = [];
        $primaryKeys = [];

        foreach ($indexes as $index) {
            if ($index['primary'] ?? false) {
                $primaryKeys = array_merge($primaryKeys, $index['columns']);
            } elseif ($index['unique'] ?? false) {
                $uniqueColumns = array_merge($uniqueColumns, $index['columns']);
            }
        }

        // Discovered foreign keys
        $foreignKeys = [];
        try {
            $fks = Schema::getForeignKeys($table);
            foreach ($fks as $fk) {
                foreach ($fk['columns'] as $idx => $col) {
                    $foreignKeys[$col] = [
                        'foreign_table' => $fk['foreign_table'],
                        'foreign_column' => $fk['foreign_columns'][$idx] ?? 'id',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Foreign key inspection may not be supported on all drivers/versions
        }

        // Columns inspection via Laravel 12 Schema::getColumns
        $rawColumns = Schema::getColumns($table);
        $importable = [];

        foreach ($rawColumns as $col) {
            $colName = $col['name'];

            // Skip excluded columns
            if (in_array($colName, $allExcluded, true)) {
                continue;
            }

            // Skip auto_increment columns (like primary id)
            if (!empty($col['auto_increment'])) {
                continue;
            }

            // Skip generated columns
            if (!empty($col['generation'])) {
                continue;
            }

            $isNullable = (bool) ($col['nullable'] ?? false);
            $hasDefault = array_key_exists('default', $col) && $col['default'] !== null;
            $isRequired = !$isNullable && !$hasDefault;

            $typeName = strtolower($col['type_name'] ?? 'string');
            $dataType = $this->normalizeDataType($typeName, $col['type'] ?? '');

            $importable[$colName] = [
                'name' => $colName,
                'label' => $this->generateColumnLabel($colName),
                'type' => $dataType,
                'raw_type' => $col['type'] ?? $typeName,
                'nullable' => $isNullable,
                'has_default' => $hasDefault,
                'default' => $col['default'] ?? null,
                'required' => $isRequired,
                'is_writable' => true,
                'is_auto_increment' => false,
                'is_unique' => in_array($colName, $uniqueColumns, true) || in_array($colName, $primaryKeys, true),
                'is_primary' => in_array($colName, $primaryKeys, true),
                'foreign_key' => $foreignKeys[$colName] ?? null,
                'description' => $col['comment'] ?? null,
            ];
        }

        // Virtual columns for foreign key resolution
        if ($table === 'medical_histories' || $table === 'invoices') {
            if (!isset($importable['contact'])) {
                $importable['contact'] = [
                    'name' => 'contact',
                    'label' => 'Patient Contact / Phone',
                    'type' => 'string',
                    'raw_type' => 'varchar(255)',
                    'nullable' => true,
                    'has_default' => false,
                    'default' => null,
                    'required' => false,
                    'is_writable' => true,
                    'is_auto_increment' => false,
                    'is_unique' => false,
                    'foreign_key' => [
                        'foreign_table' => 'patients',
                        'foreign_column' => 'phone',
                    ],
                    'comment' => 'Patient contact/phone number used to automatically resolve and link patient_id',
                ];
            }
        }

        // For medical_histories and invoices: patient_id is auto-resolved from contact/phone
        // at normalizeRow time, so it must NOT be flagged as required in the schema —
        // doing so would block users whose spreadsheet only has a contact column.
        if (($table === 'medical_histories' || $table === 'invoices') && isset($importable['patient_id'])) {
            $importable['patient_id']['required'] = false;
            $importable['patient_id']['nullable'] = true;
            $importable['patient_id']['has_default'] = true; // tells proposeMapping it is covered
            $importable['patient_id']['description'] = 'Auto-resolved from the Contact / Phone column. Leave unmapped if using Contact.';
        }

        if ($table === 'medical_histories') {

            if (!isset($importable['patient_name'])) {
                $importable['patient_name'] = [
                    'name' => 'patient_name',
                    'label' => 'Patient Name / Full Name',
                    'type' => 'string',
                    'raw_type' => 'varchar(255)',
                    'nullable' => true,
                    'has_default' => false,
                    'default' => null,
                    'required' => false,
                    'is_writable' => true,
                    'is_auto_increment' => false,
                    'is_unique' => false,
                    'is_primary' => false,
                    'foreign_key' => null,
                    'comment' => 'Patient name used to automatically resolve or create the patient record',
                ];
            }

            // Virtual patient demographic columns — mapped values update the linked patient record
            if (!isset($importable['age'])) {
                $importable['age'] = [
                    'name' => 'age',
                    'label' => 'Patient Age',
                    'type' => 'integer',
                    'raw_type' => 'tinyint unsigned',
                    'nullable' => true,
                    'has_default' => false,
                    'default' => null,
                    'required' => false,
                    'is_writable' => true,
                    'is_auto_increment' => false,
                    'is_unique' => false,
                    'is_primary' => false,
                    'foreign_key' => null,
                    'comment' => 'Patient age — updates the linked patient record',
                ];
            }
            if (!isset($importable['gender'])) {
                $importable['gender'] = [
                    'name' => 'gender',
                    'label' => 'Patient Gender',
                    'type' => 'string',
                    'raw_type' => "enum('Male','Female','Other')",
                    'nullable' => true,
                    'has_default' => false,
                    'default' => null,
                    'required' => false,
                    'is_writable' => true,
                    'is_auto_increment' => false,
                    'is_unique' => false,
                    'is_primary' => false,
                    'foreign_key' => null,
                    'comment' => 'Patient gender — updates the linked patient record',
                ];
            }
            if (!isset($importable['address'])) {
                $importable['address'] = [
                    'name' => 'address',
                    'label' => 'Patient Address',
                    'type' => 'string',
                    'raw_type' => 'varchar(255)',
                    'nullable' => true,
                    'has_default' => false,
                    'default' => null,
                    'required' => false,
                    'is_writable' => true,
                    'is_auto_increment' => false,
                    'is_unique' => false,
                    'is_primary' => false,
                    'foreign_key' => null,
                    'comment' => 'Patient address — updates the linked patient record',
                ];
            }
        }

        return $importable;
    }

    /**
     * Map database type names to normalized high-level data types.
     */
    protected function normalizeDataType(string $typeName, string $fullType): string
    {
        $typeName = strtolower($typeName);

        if (Str::contains($typeName, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'])) {
            if ($typeName === 'tinyint' && Str::contains($fullType, 'tinyint(1)')) {
                return 'boolean';
            }
            return 'integer';
        }

        if (Str::contains($typeName, ['decimal', 'float', 'double', 'numeric', 'real'])) {
            return 'decimal';
        }

        if (Str::contains($typeName, ['bool', 'boolean'])) {
            return 'boolean';
        }

        if (Str::contains($typeName, ['datetime', 'timestamp'])) {
            return 'datetime';
        }

        if (Str::contains($typeName, ['date'])) {
            return 'date';
        }

        if (Str::contains($typeName, ['time'])) {
            return 'time';
        }

        if (Str::contains($typeName, ['json'])) {
            return 'json';
        }

        if (Str::contains($typeName, ['text', 'longtext', 'mediumtext'])) {
            return 'text';
        }

        return 'string';
    }

    /**
     * Generate human-readable label from column name.
     */
    public function generateColumnLabel(string $colName): string
    {
        $customLabels = [
            'dob' => 'Date of Birth',
            'date_of_birth' => 'Date of Birth',
            'role_id' => 'Role ID',
            'branch_id' => 'Branch ID',
            // 'patient_id' => 'Patient ID / Contact',
            'doctor_id' => 'Doctor ID',
            'qty' => 'Quantity',
            'contact' => 'Patient Contact / Phone',
        ];

        if (isset($customLabels[$colName])) {
            return $customLabels[$colName];
        }

        return Str::headline($colName);
    }

    /**
     * Clear cached column metadata for a table (e.g. after migrations).
     */
    public function clearCache(string $table): void
    {
        Cache::forget("universal_import_columns_{$table}");
    }
}
