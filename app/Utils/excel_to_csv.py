#!/usr/bin/env python3
"""Convert Excel file to CSV for data migration."""

import sys
import csv
from openpyxl import load_workbook

# Sheets with this title (case-insensitive) are documentation/legend sheets,
# not data, and are skipped in both conversion modes.
RESERVED_SHEET_NAMES = {'instructions'}


def _read_sheet(ws):
    """Return (header_row, records) for a worksheet, or (None, []) if empty."""
    header_row = None
    for row in ws.iter_rows(min_row=1, max_row=1, values_only=True):
        header_row = [str(c).strip() if c is not None else '' for c in row]

    if not header_row or all(h == '' for h in header_row):
        return None, []

    records = []
    for row in ws.iter_rows(min_row=2, max_row=ws.max_row, values_only=True):
        if not any(cell is not None and str(cell).strip() != '' for cell in row):
            continue
        record = {}
        for i, h in enumerate(header_row):
            if not h:
                continue
            value = row[i] if i < len(row) else None
            record[h] = value
        records.append(record)

    return header_row, records


def excel_to_csv(xlsx_path, csv_path, merge_sheets=False):
    """Convert XLSX file to CSV format.

    Default mode converts only the first (active) sheet, unchanged from the
    original behavior - other callers (e.g. the single-type dynamic-mapping
    importer) depend on this staying exactly as-is.

    merge_sheets=True instead reads every sheet except reserved ones (e.g.
    "Instructions") and merges them into one CSV with a unified header (a
    union of every sheet's columns, matched by name). This lets a workbook
    split its rows across one sheet per row-type instead of one wide sheet,
    while each sheet keeps its own explicit 'type' column values as before.
    """
    try:
        wb = load_workbook(xlsx_path, data_only=True)

        if not merge_sheets:
            ws = wb.active

            with open(csv_path, 'w', newline='', encoding='utf-8') as csvfile:
                writer = csv.writer(csvfile)
                for row in ws.iter_rows(min_row=1, max_row=ws.max_row, values_only=True):
                    if any(cell is not None for cell in row):
                        csv_row = [str(cell) if cell is not None else '' for cell in row]
                        writer.writerow(csv_row)

            return True

        sheets = [ws for ws in wb.worksheets if ws.title.strip().lower() not in RESERVED_SHEET_NAMES]

        union_header = []
        union_header_lower = set()
        all_records = []

        for ws in sheets:
            header_row, records = _read_sheet(ws)
            if not header_row:
                continue

            for h in header_row:
                if h and h.lower() not in union_header_lower:
                    union_header.append(h)
                    union_header_lower.add(h.lower())

            all_records.extend(records)

        if not union_header:
            return False

        with open(csv_path, 'w', newline='', encoding='utf-8') as csvfile:
            writer = csv.writer(csvfile)
            writer.writerow(union_header)
            for record in all_records:
                lower_map = {k.lower(): v for k, v in record.items()}
                out_row = []
                for h in union_header:
                    v = lower_map.get(h.lower())
                    out_row.append('' if v is None else str(v))
                writer.writerow(out_row)

        return True
    except Exception as e:
        print(f"Error: {str(e)}", file=sys.stderr)
        return False


if __name__ == '__main__':
    if len(sys.argv) not in (3, 4):
        print("Usage: python excel_to_csv.py <xlsx_path> <csv_path> [--merge-sheets]")
        sys.exit(1)

    xlsx_path = sys.argv[1]
    csv_path = sys.argv[2]
    merge_sheets = len(sys.argv) == 4 and sys.argv[3] == '--merge-sheets'

    if excel_to_csv(xlsx_path, csv_path, merge_sheets=merge_sheets):
        print("OK", file=sys.stdout)
        sys.exit(0)
    else:
        print("FAILED", file=sys.stderr)
        sys.exit(1)
