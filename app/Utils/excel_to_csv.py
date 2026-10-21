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


def excel_to_csv(xlsx_path, csv_path, merge_sheets=False, target_sheet=None):
    """Convert XLSX file to CSV format.

    Default mode converts only the first (active) sheet, or target_sheet if specified.
    """
    try:
        wb = load_workbook(xlsx_path, data_only=True)

        if not merge_sheets:
            if target_sheet and target_sheet in wb.sheetnames:
                ws = wb[target_sheet]
            else:
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

        # Map worksheet titles to normalized type names
        sheet_to_type = {
            'employee': 'employee', 'employees': 'employee',
            'client': 'client', 'clients': 'client',
            'patient': 'patient', 'patients': 'patient',
            'odontogram': 'odontogram',
            'periodontal': 'periodontal', 'periodontal chart': 'periodontal',
            'orthodontic': 'orthodontic', 'orthodontic chart': 'orthodontic',
            'endodontic': 'endodontic', 'endodontic chart': 'endodontic',
            'appointment': 'appointment', 'appointments': 'appointment',
            'clinical': 'clinical',
            'financial': 'financial', 'invoices': 'financial',
            'lab order': 'lab_order', 'lab orders': 'lab_order', 'lab_order': 'lab_order', 'lab_orders': 'lab_order',
            'payroll': 'payroll',
            'leave request': 'leave_request', 'leave requests': 'leave_request', 'leave_request': 'leave_request',
            'staff advance': 'staff_advance', 'staff advances': 'staff_advance', 'staff_advance': 'staff_advance',
            'insurance company': 'insurance_company', 'insurance companies': 'insurance_company', 'insurance_company': 'insurance_company',
            'insurance package': 'insurance_package', 'insurance packages': 'insurance_package', 'insurance_package': 'insurance_package',
            'insurance coverage': 'insurance_coverage', 'insurance_coverage': 'insurance_coverage',
            'insurance policy': 'insurance_policy', 'insurance policies': 'insurance_policy', 'insurance_policy': 'insurance_policy',
            'insurance enrollment': 'insurance', 'insurance': 'insurance',
            'claim': 'claim', 'claims': 'claim',
            'payment': 'payment', 'payments': 'payment',
        }

        for ws in sheets:
            header_row, records = _read_sheet(ws)
            if not header_row:
                continue

            sheet_title_key = ws.title.strip().lower()
            inferred_type = sheet_to_type.get(sheet_title_key)

            # Ensure 'type' is present in the union header
            if 'type' not in union_header_lower:
                union_header.insert(0, 'type')
                union_header_lower.add('type')

            for h in header_row:
                if h and h.lower() not in union_header_lower:
                    union_header.append(h)
                    union_header_lower.add(h.lower())

            # If sheet records lack 'type' or have it empty, auto-populate from sheet name
            for rec in records:
                if inferred_type and (not rec.get('type') or str(rec.get('type')).strip() == ''):
                    rec['type'] = inferred_type
                all_records.append(rec)

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
    if len(sys.argv) < 3:
        print("Usage: python excel_to_csv.py <xlsx_path> <csv_path> [--merge-sheets] [--sheet=SheetName]")
        sys.exit(1)

    xlsx_path = sys.argv[1]
    csv_path = sys.argv[2]
    merge_sheets = '--merge-sheets' in sys.argv[3:]
    target_sheet = None
    for arg in sys.argv[3:]:
        if arg.startswith('--sheet='):
            target_sheet = arg.split('=', 1)[1]

    if excel_to_csv(xlsx_path, csv_path, merge_sheets=merge_sheets, target_sheet=target_sheet):
        print("OK", file=sys.stdout)
        sys.exit(0)
    else:
        print("FAILED", file=sys.stderr)
        sys.exit(1)
