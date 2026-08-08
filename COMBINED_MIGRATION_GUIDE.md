# Combined Migration Guide

## Overview

The new `POST /migrate/combined` endpoint allows importing all data types (employees, patients, appointments, clinical records, and financial data) from a **single Excel file** with guaranteed referential integrity.

**File Format:** Excel 2007+ (.xlsx) - CSV support available

**Template Download:** `GET /api/migrate/template/download`

---

## How It Works

### Processing Order (Guarantees Referential Integrity)

Data is automatically processed in dependency order:

1. **Pass 1:** Employees (no dependencies)
2. **Pass 2:** Patients (no dependencies)
3. **Pass 3:** Appointments (references employees & patients)
4. **Pass 4:** Clinical records (references patients)
5. **Pass 5:** Financial records (references patients)

This ensures that when processing appointments, employees and patients have already been imported, preventing foreign key violations.

---

## File Format

### Template Download

**Download the template:** `GET /api/migrate/template/download`

The template includes:
- Pre-formatted Excel sheet with all required columns
- Instructions sheet with detailed guidance
- 17 sample records (3 employees, 4 patients, 3 appointments, 3 clinical, 4 financial)
- Color-coded rows for each data type
- Ready-to-use as a starting point for your data

### Column Format

Each row must have a **type** column indicating the data type.

**Supported Columns (in any order):**

```
type, first_name, last_name, email, phone, patient_email, doctor_email, 
procedure, cost, date, amount, status, role, salary, hire_date, gender, 
date_of_birth, time, treatment
```

**Valid type values:**
- `employee`
- `patient`
- `appointment`
- `clinical`
- `financial`

### Column Requirements by Type

#### Employee Rows
```
type,first_name,last_name,email,phone,role,salary,hire_date,status
employee,John,Smith,john@clinic.com,1234567890,Dentist,50000,2020-01-15,Active
```

**Required columns:**
- `type` = "employee"
- `email` (unique, valid format)
- `first_name` or `name`

**Optional columns:**
- `phone`
- `role` (default: Dentist)
- `salary` (must be numeric)
- `hire_date` (must be valid date, not future)
- `status` (Active/On Leave)

#### Patient Rows
```
type,first_name,last_name,email,phone,gender,date_of_birth,status
patient,Jane,Doe,jane@clinic.com,0987654321,Female,1990-05-20,Active
```

**Required columns:**
- `type` = "patient"
- `email` OR `phone` (at least one required)
- `first_name` or `name`

**Optional columns:**
- `last_name`
- `phone`
- `gender` (Male/Female/Other)
- `date_of_birth` (must be valid date, not future)
- `status` (Active/Inactive)
- `address`

#### Appointment Rows
```
type,patient_email,doctor_email,date,time,treatment,status
appointment,,bob@clinic.com,john@clinic.com,2026-08-15,10:00,Cleaning,Scheduled
```

**Required columns:**
- `type` = "appointment"
- `patient_email` (must exist in patients)
- `doctor_email` (must exist in employees)
- `date` (valid date format)

**Optional columns:**
- `time` (HH:MM format)
- `treatment` (default: General Checkup)
- `status` (Scheduled/Completed/Cancelled/Waiting)

#### Clinical Rows
```
type,patient_email,procedure,cost,date,status
clinical,,bob@clinic.com,Filling,150,2026-08-15,Completed
```

**Required columns:**
- `type` = "clinical"
- `patient_email` (must exist in patients)
- `cost` (must be numeric)

**Optional columns:**
- `procedure` (default: General Checkup)
- `date` (valid date)
- `status` (Proposed/Completed)

#### Financial Rows
```
type,patient_email,amount,date,status
financial,,alice@clinic.com,200,2026-08-20,Paid
```

**Required columns:**
- `type` = "financial"
- `patient_email` (must exist in patients)
- `amount` (positive number)

**Optional columns:**
- `date` (valid date)
- `status` (Pending/Paid/Overdue)

---

## Example: Complete CSV

```csv
type,first_name,last_name,email,phone,role,salary,patient_email,doctor_email,procedure,cost,amount,date,status,gender,date_of_birth
employee,John,Smith,john@clinic.com,1234567890,Dentist,50000,,,,,,,,
employee,Jane,Doe,jane@clinic.com,0987654321,Hygienist,35000,,,,,,,,
patient,Bob,,bob@clinic.com,5551234567,,,,,,,,,Male,1985-03-15
patient,Alice,,alice@clinic.com,5559876543,,,,,,,,,Female,1992-07-20
appointment,,,bob@clinic.com,john@clinic.com,,,,,,2026-08-15,Scheduled,,
appointment,,,alice@clinic.com,jane@clinic.com,,,,,,2026-08-20,Scheduled,,
clinical,,,bob@clinic.com,,Filling,150,,,,2026-08-15,Completed,,
clinical,,,alice@clinic.com,,Crown,500,,,,2026-08-20,Completed,,
financial,,,bob@clinic.com,,,,200,2026-08-22,Paid,,
financial,,,alice@clinic.com,,,,300,2026-08-25,Paid,,
```

---

## API Response

### Success (200 OK)
```json
{
  "success": true,
  "stats": {
    "total": 10,
    "imported": 10,
    "failed": 0,
    "skipped": 0,
    "duration": 2.45
  },
  "errors": [],
  "warnings": [],
  "import_id": "imp_abc123",
  "import_type": "combined"
}
```

### Error (400 Bad Request)
```json
{
  "success": false,
  "stats": {
    "total": 10,
    "imported": 8,
    "failed": 2,
    "skipped": 0,
    "duration": 1.88
  },
  "errors": [
    {
      "row": 5,
      "field": "email",
      "value": "invalid-email@",
      "reason": "Invalid email format"
    },
    {
      "row": 7,
      "field": "cost",
      "value": "abc",
      "reason": "Cost must be a number"
    }
  ],
  "warnings": [
    "Row 2: Email already exists (will update)"
  ],
  "import_id": "imp_abc123",
  "import_type": "combined"
}
```

---

## Key Differences from Single-Section Import

| Aspect | Single Section | Combined |
|--------|---|---|
| **Endpoint** | `POST /migrate` | `POST /migrate/combined` |
| **Sections** | One at a time | All in one file |
| **Type column** | Not needed | Required |
| **Processing order** | Strict (1→2→3→4→5) | Automatic, guaranteed |
| **File size** | Limited | Up to 50MB |
| **Import ID** | Single | Single (all sections) |

---

## Validation Rules

### Referential Integrity

- Employees MUST be imported before appointments can reference them
- Patients MUST be imported before appointments/clinical/financial records reference them
- The system automatically handles this via multi-pass processing

### Data Validation

- **Emails**: Valid format, unique per section (no duplicates)
- **Phones**: 10-20 digits
- **Dates**: Valid date format (YYYY-MM-DD), not future dates
- **Numbers**: Must be numeric (salary, cost, amount)
- **Required fields**: Enforced per type

### Error Handling

- Validation errors return with row number and field details
- Failed rows are skipped, others continue processing
- Errors are returned in response (max 100 shown)
- Warnings for duplicates/recoverable issues (max 50 shown)

---

## Usage Examples

### Download Template

```bash
curl -X GET http://api.clinic.com/api/migrate/template/download \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -o combined-migration-template.xlsx
```

### Upload Excel File via cURL

```bash
curl -X POST http://api.clinic.com/api/migrate/combined \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@data.xlsx"
```

### Upload Excel File via JavaScript/Frontend

```javascript
// Download template first
async function downloadTemplate() {
  const response = await fetch('/api/migrate/template/download', {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  const blob = await response.blob();
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'combined-migration-template.xlsx';
  a.click();
}

// Upload filled Excel file
async function uploadMigration(excelFile) {
  const formData = new FormData();
  formData.append('file', excelFile);

  const response = await fetch('/api/migrate/combined', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`
    },
    body: formData
  });

  const result = await response.json();
  console.log(`Imported: ${result.stats.imported}, Failed: ${result.stats.failed}`);
  
  if (result.errors && result.errors.length > 0) {
    console.log('Errors:', result.errors.slice(0, 10));
  }
}
```

---

## Troubleshooting

### "type" column missing
**Error:** `CSV must have a "type" column`
**Solution:** Add a `type` column with values: employee, patient, appointment, clinical, financial

### Invalid type value
**Error:** Row skipped (type not recognized)
**Solution:** Ensure type is exactly one of: employee, patient, appointment, clinical, financial (lowercase)

### Foreign key reference fails
**Error:** Appointment references non-existent employee
**Solution:** Ensure the employee row appears BEFORE the appointment row in the CSV

### Email validation fails
**Error:** `Invalid email format`
**Solution:** Check email format (must be valid email like name@domain.com)

### Date is in future
**Error:** `Birth date cannot be in future`
**Solution:** Use valid past dates for birth dates and historical records

---

## Performance

- **Chunking:** Processes 1,000 records per chunk for memory efficiency
- **Multi-pass:** 5 passes through data (employees, patients, appointments, clinical, financial)
- **Typical speed:** ~1,000 records per second
- **Maximum file size:** 50MB

---

## Comparison with Original System

### Before (5 Separate CSVs)
1. Upload employees CSV → Wait for completion
2. Upload patients CSV → Wait for completion
3. Upload appointments CSV → Wait for completion
4. Upload clinical CSV → Wait for completion
5. Upload financial CSV → Wait for completion
**Total time:** 5+ uploads

### After (Single Combined CSV)
1. Upload combined CSV → All 5 sections processed in order
**Total time:** 1 upload, multi-pass processing

---

## Template & Sample Files

### Official Template (Excel Format)

**Download:** `GET /api/migrate/template/download`

This is the recommended way to get started:
- Includes 17 sample records across all 5 types
- Pre-formatted columns in correct order
- Instructions sheet with guidance
- Color-coded by data type for easy visual reference
- Ready to edit and use

**File:** `combined-migration-template.xlsx`
- Contains both data rows and instructions sheet
- All required columns already included
- Sample data shows proper format
- Simply edit the data and upload

---

## Migration Checklist

- [ ] Prepare CSV with all data
- [ ] Add `type` column with correct values
- [ ] Verify all required columns present
- [ ] Check data formats (dates, emails, numbers)
- [ ] Ensure no circular dependencies
- [ ] Test with sample data first
- [ ] Review error response
- [ ] Import production data
- [ ] Verify import statistics

