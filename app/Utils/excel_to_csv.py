import sys
import os
import openpyxl
import csv

def main():
    if len(sys.argv) < 3:
        print("Usage: python excel_to_csv.py <input_xlsx> <output_csv>", file=sys.stderr)
        sys.exit(1)

    input_path = sys.argv[1]
    output_path = sys.argv[2]

    if not os.path.exists(input_path):
        print(f"Input file not found: {input_path}", file=sys.stderr)
        sys.exit(1)

    try:
        # Load workbook in read_only mode for maximum speed and minimum memory usage
        wb = openpyxl.load_workbook(input_path, read_only=True, data_only=True)
        sheet = wb.active

        # Ensure directory of output exists
        os.makedirs(os.path.dirname(output_path), exist_ok=True)

        with open(output_path, 'w', newline='', encoding='utf-8') as f:
            writer = csv.writer(f)
            for row in sheet.iter_rows(values_only=True):
                # Skip rows that are completely empty
                if row is None or all(val is None or str(val).strip() == "" for val in row):
                    continue
                
                # Format cell values to string or appropriate types (e.g. replace None with empty string)
                formatted_row = [str(val).strip() if val is not None else "" for val in row]
                writer.writerow(formatted_row)

        wb.close()
        print("Conversion completed successfully.")
        sys.exit(0)

    except Exception as e:
        print(f"Error converting file: {str(e)}", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
