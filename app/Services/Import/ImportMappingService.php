<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ImportMappingService
{
    protected ImportSchemaService $schemaService;

    public function __construct(ImportSchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
    }

    /**
     * Propose mapping between spreadsheet headers and destination table columns.
     */
    public function proposeMapping(array $headers, string $table): array
    {
        $columns = $this->schemaService->getImportableColumns($table);
        $tableConfig = $this->schemaService->getTableConfig($table) ?? [];
        $tableAliases = $tableConfig['aliases'] ?? [];
        $globalAliases = Config::get('import.aliases', []);

        $mapping = [];
        $suggestions = [];
        $usedDestinations = [];

        // 1. First pass: exact matches and known aliases
        foreach ($headers as $header) {
            $trimmedHeader = trim((string)$header);
            if ($trimmedHeader === '') {
                continue;
            }

            $normalizedHeader = $this->normalizeString($trimmedHeader);
            $matchedColumn = null;

            // Direct match with column name or normalized column name
            foreach ($columns as $colName => $colMeta) {
                if ($this->normalizeString($colName) === $normalizedHeader || strcasecmp($colName, $trimmedHeader) === 0) {
                    $matchedColumn = $colName;
                    break;
                }
            }

            // Check table-specific aliases first (e.g. for patients, 'date' => 'last_visit')
            if (!$matchedColumn) {
                foreach ($tableAliases as $targetCol => $aliasList) {
                    if (!isset($columns[$targetCol])) {
                        continue;
                    }
                    foreach ((array)$aliasList as $alias) {
                        if ($this->normalizeString($alias) === $normalizedHeader || strcasecmp($alias, $trimmedHeader) === 0) {
                            $matchedColumn = $targetCol;
                            break 2;
                        }
                    }
                }
            }

            // Check global aliases
            if (!$matchedColumn) {
                foreach ($globalAliases as $targetCol => $aliasList) {
                    if (!isset($columns[$targetCol])) {
                        continue;
                    }
                    foreach ((array)$aliasList as $alias) {
                        if ($this->normalizeString($alias) === $normalizedHeader || strcasecmp($alias, $trimmedHeader) === 0) {
                            $matchedColumn = $targetCol;
                            break 2;
                        }
                    }
                }
            }

            if ($matchedColumn && !isset($usedDestinations[$matchedColumn])) {
                $mapping[$trimmedHeader] = $matchedColumn;
                $usedDestinations[$matchedColumn] = true;
            }
        }

        // 2. Second pass: safe fuzzy suggestions for unmapped headers
        foreach ($headers as $header) {
            $trimmedHeader = trim((string)$header);
            if ($trimmedHeader === '' || isset($mapping[$trimmedHeader])) {
                continue;
            }

            $normalizedHeader = $this->normalizeString($trimmedHeader);
            $bestSuggestions = [];

            foreach ($columns as $colName => $colMeta) {
                // Skip columns already exactly mapped
                if (isset($usedDestinations[$colName])) {
                    continue;
                }

                $simScore = 0;
                similar_text($normalizedHeader, $this->normalizeString($colName), $simScore);

                // Also compare against label
                $labelSim = 0;
                similar_text($normalizedHeader, $this->normalizeString($colMeta['label']), $labelSim);
                $simScore = max($simScore, $labelSim);

                if ($simScore >= 70) {
                    $bestSuggestions[] = [
                        'column' => $colName,
                        'label' => $colMeta['label'],
                        'similarity' => round($simScore, 1),
                    ];
                }
            }

            if (!empty($bestSuggestions)) {
                usort($bestSuggestions, function ($a, $b) {
                    return $b['similarity'] <=> $a['similarity'];
                });
                $suggestions[$trimmedHeader] = array_slice($bestSuggestions, 0, 3);
            }
        }

        // 3. Find missing required fields
        $mappedDestinationColumns = array_values($mapping);
        $missingRequired = [];
        $tableConfig = $this->schemaService->getTableConfig($table);
        $defaults = $tableConfig['defaults'] ?? [];
        $tenantColumn = $tableConfig['tenant_column'] ?? null;

        foreach ($columns as $colName => $colMeta) {
            if ($tenantColumn && $colName === $tenantColumn) {
                continue;
            }

            if ($colMeta['required'] && !in_array($colName, $mappedDestinationColumns, true)) {
                if (!array_key_exists($colName, $defaults)) {
                    $missingRequired[] = [
                        'column' => $colName,
                        'label' => $colMeta['label'],
                        'type' => $colMeta['type'],
                    ];
                }
            }
        }

        // 4. Find unmapped headers
        $unmappedHeaders = [];
        foreach ($headers as $header) {
            $trimmedHeader = trim((string)$header);
            if ($trimmedHeader !== '' && !isset($mapping[$trimmedHeader])) {
                $unmappedHeaders[] = $trimmedHeader;
            }
        }

        return [
            'mapping' => $mapping,
            'suggestions' => $suggestions,
            'unmapped_headers' => $unmappedHeaders,
            'missing_required_fields' => $missingRequired,
        ];
    }

    /**
     * Validate an approved mapping before execution.
     */
    public function validateMapping(array $mapping, string $table): array
    {
        $columns = $this->schemaService->getImportableColumns($table);
        $errors = [];
        $destinationCounts = [];

        $tableConfig = $this->schemaService->getTableConfig($table) ?? [];
        $tableAliases = $tableConfig['aliases'] ?? [];
        $globalAliases = Config::get('import.aliases', []);

        foreach ($mapping as $sourceHeader => $destinationCol) {
            if (empty($destinationCol)) {
                continue; // skipped/ignored column
            }

            // If destinationCol is not directly a column, check if it's a known alias
            if (!isset($columns[$destinationCol])) {
                $resolved = null;
                $normDest = strtolower(trim($destinationCol));

                // Check table aliases first
                foreach ($tableAliases as $targetCol => $aliasList) {
                    if (isset($columns[$targetCol])) {
                        foreach ((array)$aliasList as $alias) {
                            if (strtolower(trim($alias)) === $normDest || $this->normalizeString($alias) === $this->normalizeString($destinationCol)) {
                                $resolved = $targetCol;
                                break 2;
                            }
                        }
                    }
                }

                // Check global aliases
                if (!$resolved) {
                    foreach ($globalAliases as $targetCol => $aliasList) {
                        if (isset($columns[$targetCol])) {
                            foreach ((array)$aliasList as $alias) {
                                if (strtolower(trim($alias)) === $normDest || $this->normalizeString($alias) === $this->normalizeString($destinationCol)) {
                                    $resolved = $targetCol;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                if ($resolved) {
                    $destinationCol = $resolved;
                    $mapping[$sourceHeader] = $resolved;
                } else {
                    $errors[] = "Destination column '{$destinationCol}' does not exist or is not importable in table '{$table}'.";
                    continue;
                }
            }

            $destinationCounts[$destinationCol] = ($destinationCounts[$destinationCol] ?? 0) + 1;
        }

        // Prevent duplicate destination mappings
        foreach ($destinationCounts as $col => $count) {
            if ($count > 1) {
                $errors[] = "Destination column '{$col}' is mapped multiple times ({$count} source columns).";
            }
        }

        // Verify required columns that don't have defaults or tenant scoping
        $mappedCols = array_values(array_filter($mapping));
        $tableConfig = $this->schemaService->getTableConfig($table);
        $defaults = $tableConfig['defaults'] ?? [];
        $tenantColumn = $tableConfig['tenant_column'] ?? null;

        foreach ($columns as $colName => $colMeta) {
            // Tenant column is injected automatically by system context
            if ($tenantColumn && $colName === $tenantColumn) {
                continue;
            }

            if ($colMeta['required'] && !in_array($colName, $mappedCols, true)) {
                // If there's a configured table default, it's allowed
                if (!array_key_exists($colName, $defaults)) {
                    $errors[] = "Required column '{$colMeta['label']}' ({$colName}) is missing from the column mapping.";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'resolved_mapping' => $mapping,
        ];
    }

    /**
     * Normalize string for reliable matching (remove accents, punctuation, lowercases).
     */
    public function normalizeString(string $value): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_strtolower(trim($value)));
        return trim($clean, '_');
    }
}
