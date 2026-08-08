#!/usr/bin/env python3
"""Convert Excel file to CSV for data migration."""

import sys
import csv
from openpyxl import load_workbook

def excel_to_csv(xlsx_path, csv_path):
    """Convert XLSX file to CSV format."""
    try:
        # Load workbook
        wb = load_workbook(xlsx_path, data_only=True)

        # Use the first sheet
        ws = wb.active

        # Write to CSV
        with open(csv_path, 'w', newline='', encoding='utf-8') as csvfile:
            writer = csv.writer(csvfile)

            # Iterate through all rows
            for row in ws.iter_rows(min_row=1, max_row=ws.max_row, values_only=True):
                # Skip completely empty rows
                if any(cell is not None for cell in row):
                    # Convert all values to strings, empty cells become empty strings
                    csv_row = [str(cell) if cell is not None else '' for cell in row]
                    writer.writerow(csv_row)

        return True
    except Exception as e:
        print(f"Error: {str(e)}", file=sys.stderr)
        return False

if __name__ == '__main__':
    if len(sys.argv) != 3:
        print("Usage: python excel_to_csv.py <xlsx_path> <csv_path>")
        sys.exit(1)

    xlsx_path = sys.argv[1]
    csv_path = sys.argv[2]

    if excel_to_csv(xlsx_path, csv_path):
        print("OK", file=sys.stdout)
        sys.exit(0)
    else:
        print("FAILED", file=sys.stderr)
        sys.exit(1)
