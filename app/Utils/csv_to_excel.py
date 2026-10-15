#!/usr/bin/env python3
"""Convert CSV file to Excel XLSX format."""

import sys
import csv
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

def csv_to_excel(csv_path, xlsx_path, sheet_name="Data"):
    wb = Workbook()
    ws = wb.active
    ws.title = sheet_name

    header_font = Font(name="Calibri", size=11, bold=True, color="FFFFFF")
    header_fill = PatternFill(start_color="1E3A8A", end_color="1E3A8A", fill_type="solid")
    regular_font = Font(name="Calibri", size=10)
    border_side = Side(style='thin', color="E5E7EB")
    cell_border = Border(left=border_side, right=border_side, top=border_side, bottom=border_side)

    with open(csv_path, 'r', encoding='utf-8', errors='replace') as f:
        reader = csv.reader(f)
        for row_idx, row in enumerate(reader, start=1):
            for col_idx, value in enumerate(row, start=1):
                cell = ws.cell(row=row_idx, column=col_idx, value=value)
                cell.border = cell_border
                if row_idx == 1:
                    cell.font = header_font
                    cell.fill = header_fill
                    cell.alignment = Alignment(horizontal="center", vertical="center")
                else:
                    cell.font = regular_font
                    cell.alignment = Alignment(vertical="center")

    # Auto-adjust column widths
    for col in ws.columns:
        max_len = 0
        col_letter = get_column_letter(col[0].column)
        for cell in col:
            val_str = str(cell.value or '')
            if len(val_str) > max_len:
                max_len = len(val_str)
        ws.column_dimensions[col_letter].width = min(max(max_len + 3, 12), 50)

    ws.freeze_panes = "A2"
    wb.save(xlsx_path)

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python csv_to_excel.py <input_csv> <output_xlsx> [sheet_name]")
        sys.exit(1)

    csv_file = sys.argv[1]
    xlsx_file = sys.argv[2]
    sheet = sys.argv[3] if len(sys.argv) > 3 else "Data"

    csv_to_excel(csv_file, xlsx_file, sheet)
