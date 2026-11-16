<?php

namespace App\Services\Import;

use Carbon\Carbon;

/**
 * Converts a clinic "visit ledger" sheet into a flat sheet for the target table.
 *
 * Ledger layout (one sheet, visits grouped under a date):
 *   - A row whose only filled cell is column A holds a visit date, e.g. "3rd/01/2026".
 *     Every patient row below it belongs to that date until the next date row.
 *   - Patient rows have PT NO., PATIENT NAME, SEX, AGE, ADRESS, PROCEDURE,
 *     TOTAL AMOUNT, AMOUNT PAID, TELEPHONE NUMBER and NEXT APPOINTMENT columns.
 *
 * Output depends on the target table:
 *   - patients: date -> last_visit, name, phone, gender, age, address, history, next_visit.
 *   - invoices: one invoice per visit row, with a receipt number built from the date and PT NO.,
 *     issue date, patient name, phone, procedure, total, paid amount and status.
 */
class ClinicLedgerNormalizer
{
    public const TARGET_PATIENTS = 'patients';
    public const TARGET_INVOICES = 'invoices';

    private const PATIENT_HEADERS = ['last_visit', 'first_name', 'phone', 'gender', 'age', 'address', 'history', 'next_visit'];
    private const INVOICE_HEADERS = ['receipt_number', 'issue_date', 'patient_name', 'contact', 'procedure', 'amount', 'paid_amount', 'status'];

    /**
     * Returns the path to a normalized CSV when the file is a ledger, or null when it is not.
     * The returned file is a new temp file; the caller is responsible for deleting it.
     * Tables other than invoices use the patient layout (the default).
     */
    public function normalize(string $csvPath, string $delimiter, ?string $table = null): ?string
    {
        $target = $table === self::TARGET_INVOICES ? self::TARGET_INVOICES : self::TARGET_PATIENTS;

        $handle = @fopen($csvPath, 'r');
        if (!$handle) {
            return null;
        }

        $headerRow = $this->readNextNonEmptyRow($handle, $delimiter);
        if ($headerRow === null || !$this->isLedgerHeader($headerRow)) {
            fclose($handle);
            return null;
        }

        $columns = $this->columnIndexes($headerRow);

        $outPath = tempnam(sys_get_temp_dir(), 'ledger_');
        if ($outPath === false) {
            fclose($handle);
            return null;
        }
        $out = fopen($outPath, 'w');

        fputcsv($out, $target === self::TARGET_INVOICES ? self::INVOICE_HEADERS : self::PATIENT_HEADERS, $delimiter, '"', '\\');

        $currentDate = null;
        $fallbackNumber = 0;

        while (($row = $this->readNextNonEmptyRow($handle, $delimiter)) !== null) {
            // Missing optional columns (e.g. no SEX column) read as empty
            $cell = fn (string $key): string => isset($columns[$key], $row[$columns[$key]])
                ? trim((string) $row[$columns[$key]])
                : '';

            $name = $this->collapseSpaces($cell('name'));
            $pt = $cell('pt');

            // A date row: column A is filled and there is no patient on this row.
            if ($name === '' && $pt === '' && $this->isLedgerDate($cell('date'))) {
                $currentDate = $cell('date');
                continue;
            }

            // Skip rows that carry nothing about a patient.
            if ($name === '' && $pt === '') {
                continue;
            }

            $visitDate = $this->parseLedgerDate($currentDate) ?? '';

            if ($target === self::TARGET_INVOICES) {
                $fallbackNumber++;
                $total = $this->parseMoney($cell('total'));
                $paid = $this->parseMoney($cell('paid'));

                fputcsv($out, [
                    $this->receiptNumber($visitDate, $pt, $fallbackNumber),
                    $visitDate,
                    $name,
                    $cell('phone'),
                    $this->collapseSpaces($cell('procedure')),
                    $total === null ? '' : $this->formatMoney($total),
                    $paid === null ? '' : $this->formatMoney($paid),
                    $this->invoiceStatus($total, $paid),
                ], $delimiter, '"', '\\');
                continue;
            }

            fputcsv($out, [
                $visitDate,
                $name,
                $cell('phone'),
                $this->normalizeGender($cell('sex')),
                $this->normalizeAge($cell('age')),
                $this->collapseSpaces($cell('address')),
                $this->collapseSpaces($cell('procedure')),
                $this->parseLedgerDate($cell('next')) ?? '',
            ], $delimiter, '"', '\\');
        }

        fclose($handle);
        fclose($out);

        return $outPath;
    }

    /**
     * True when the header row has the ledger's PATIENT NAME and PT NO. columns.
     */
    protected function isLedgerHeader(array $row): bool
    {
        $normalized = array_map(fn ($h) => $this->normalizeHeader((string) $h), $row);

        return in_array('patient name', $normalized, true)
            && in_array('pt no', $normalized, true);
    }

    /**
     * @return array<string,int> map of logical key => column index
     */
    protected function columnIndexes(array $headerRow): array
    {
        $aliases = [
            'date' => ['date'],
            'pt' => ['pt no'],
            'name' => ['patient name'],
            'sex' => ['sex'],
            'age' => ['age'],
            'address' => ['adress', 'address'],
            'procedure' => ['procedure'],
            'phone' => ['telephone number', 'telephone', 'phone', 'phone number'],
            'next' => ['next appointment'],
            'total' => ['total amount', 'total'],
            'paid' => ['amount paid', 'paid'],
        ];

        $normalized = array_map(fn ($h) => $this->normalizeHeader((string) $h), $headerRow);

        $indexes = [];
        foreach ($aliases as $key => $names) {
            foreach ($names as $name) {
                $position = array_search($name, $normalized, true);
                if ($position !== false) {
                    $indexes[$key] = $position;
                    break;
                }
            }
        }

        return $indexes;
    }

    protected function normalizeHeader(string $header): string
    {
        // "PT NO." -> "pt no", "TELEPHONE NUMBER" -> "telephone number"
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', strtolower($header))));
    }

    /**
     * Matches "3rd/01/2026", "12th/06/2026", "23th/01/2026", "1/1/2026".
     */
    protected function isLedgerDate(string $value): bool
    {
        return (bool) preg_match('/^\d{1,2}\s*(st|nd|rd|th)?\s*\/\s*\d{1,2}\s*\/\s*\d{4}$/i', trim($value));
    }

    /**
     * Converts a ledger date string, an already-converted date, or an Excel serial day number to YYYY-MM-DD.
     * Returns null when the value is not a date (e.g. "ANYTIME").
     */
    public function parseLedgerDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Date cells already converted by the xlsx reader, e.g. "2026-02-03 00:00:00"
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(\s+\d{2}:\d{2}(:\d{2})?)?$/', $value, $m)) {
            return $m[1];
        }

        // Excel serial number, e.g. "46056" or "46056.0"
        if (preg_match('/^\d{5}(\.0+)?$/', $value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        if (preg_match('/^(\d{1,2})\s*(?:st|nd|rd|th)?\s*\/\s*(\d{1,2})\s*\/\s*(\d{4})$/i', $value, $m)) {
            try {
                return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Reads a money cell. "2500000.0" -> 2500000, "80K@Visit" -> 80000, "1,000" -> 1000.
     * Returns null when the cell holds no usable amount (blank, currency other than UGX, or garbage).
     */
    public function parseMoney(string $value): ?float
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        // "80k", "100k@visit", "20k@visist": thousands
        if (preg_match('/^(\d+(?:\.\d+)?)\s*k\b/', $value, $m)) {
            return round((float) $m[1] * 1000, 2);
        }

        $clean = str_replace([',', ' ', 'ugx', 'shs'], '', $value);
        if (preg_match('/^\d+(\.\d+)?$/', $clean)) {
            return round((float) $clean, 2);
        }

        return null;
    }

    protected function formatMoney(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    /**
     * Status from the visit's total and paid amounts.
     */
    protected function invoiceStatus(?float $total, ?float $paid): string
    {
        if ($total === null || $paid === null || $paid <= 0) {
            return 'Pending';
        }

        return $paid >= $total ? 'Paid' : 'Partially Paid';
    }

    /**
     * Unique per visit row, so re-importing the same file updates the same invoices.
     */
    protected function receiptNumber(string $visitDate, string $pt, int $fallbackNumber): string
    {
        $date = $visitDate !== '' ? $visitDate : 'NODATE';
        $number = preg_match('/^\d+(\.0+)?$/', $pt) ? (string) (int) (float) $pt : 'ROW' . $fallbackNumber;

        return "LEDGER-{$date}-PT{$number}";
    }

    protected function normalizeGender(string $value): string
    {
        return match (strtoupper(trim($value))) {
            'F' => 'Female',
            'M' => 'Male',
            default => '',
        };
    }

    protected function normalizeAge(string $value): string
    {
        // "25.0" -> "25". Words such as "ADULT" are not ages and are left blank.
        return preg_match('/^\d{1,3}(\.0+)?$/', trim($value))
            ? (string) (int) (float) $value
            : '';
    }

    protected function collapseSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Reads the next row that has at least one non-empty cell.
     *
     * @return array<int,string>|null
     */
    protected function readNextNonEmptyRow($handle, string $delimiter): ?array
    {
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (empty($row) || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            // Strip a UTF-8 BOM from the first cell
            if (isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }
            return $row;
        }

        return null;
    }
}
