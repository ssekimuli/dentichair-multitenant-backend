# Universal Dynamic Data Import REST API

A production-ready, reusable import engine that dynamically discovers destination table schemas and enables secure, validated, multi-tenant imports across authorized database tables without requiring custom import classes or controllers per entity.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Authentication & Multi-Tenant Security](#2-authentication--multi-tenant-security)
3. [REST API Endpoints](#3-rest-api-endpoints)
   - [A. List Available Tables](#a-list-available-tables)
   - [B. Get Table Columns Schema](#b-get-table-columns-schema)
   - [C. Upload and Preview File](#c-upload-and-preview-file)
   - [D. Execute Import](#d-execute-import)
   - [E. Import Job Status & Progress](#e-import-job-status--progress)
   - [F. Retrieve Row Errors](#f-retrieve-row-errors)
   - [G. Download Error Report (CSV)](#g-download-error-report-csv)
   - [H. Import History](#h-import-history)
4. [Duplicate Handling Modes](#4-duplicate-handling-modes)
5. [Error Handling & Transaction Modes](#5-error-handling--transaction-modes)
6. [Security & Formula Injection Protection](#6-security--formula-injection-protection)
7. [Queue & Worker Setup](#7-queue--worker-setup)
8. [Configuration & Customization Guide](#8-configuration--customization-guide)
   - [Adding a New Authorized Table](#adding-a-new-authorized-table)
   - [Configuring Custom Validation & Defaults](#configuring-custom-validation--defaults)
9. [End-to-End cURL Workflow Example](#9-end-to-end-curl-workflow-example)
10. [Known Limitations](#10-known-limitations)

---

## 1. Architecture Overview

The system is organized into decoupled services following clean Laravel architecture:

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   └── UniversalImportController.php  <-- REST API endpoints
│   └── Requests/Import/
│       ├── ImportPreviewRequest.php        <-- File & table upload validation
│       └── ImportExecuteRequest.php        <-- Mapping & execution payload validation
├── Services/Import/
│   ├── ImportSchemaService.php             <-- Dynamic DB schema discovery & column metadata
│   ├── ImportMappingService.php            <-- Header normalization, alias matching, suggestions
│   ├── ImportValidationService.php         <-- Type conversion, validation & tenant scoping
│   ├── SpreadsheetReaderService.php        <-- Streaming CSV & Excel chunk reader
│   └── UniversalImportService.php          <-- Orchestrator: transactions, chunks & execution
├── Jobs/
│   └── ProcessUniversalImportJob.php       <-- Asynchronous queue worker for large imports
└── Models/
    ├── ImportJob.php                       <-- Import job tracking & history
    └── ImportRowError.php                  <-- Granular row-level error reporting
config/
└── import.php                              <-- Allowlist, table rules, aliases & thresholds
database/migrations/
└── 2026_10_21_200000_create_universal_import_tables.php
```

---

## 2. Authentication & Multi-Tenant Security

- **Authentication**: All endpoints require Laravel Sanctum token authentication (`auth:sanctum`).
- **Middleware**: Integrated with `default_branch` middleware.
- **Tenant Isolation**:
  - Tables with a configured `tenant_column` (such as `branch_id`) automatically derive and enforce the authenticated user's branch context.
  - The client is never trusted to supply arbitrary `branch_id` values.
  - Unique checks and duplicate searches are automatically scoped to the tenant's branch.
- **Authorization**:
  - The API rejects any table not explicitly allowlisted in `config/import.php`.
  - Sensitive internal tables (e.g. `users`, `personal_access_tokens`, `migrations`, `password_reset_tokens`, `jobs`) are strictly excluded.
  - Role-based permissions (e.g. `manage_employees`) are verified before granting access to specific tables.

---

## 3. REST API Endpoints

All responses follow the standardized envelope:
```json
{
  "success": true,
  "message": "Human readable status description.",
  "data": { ... }
}
```

### A. List Available Tables

Returns only tables the authenticated user is authorized to import into.

- **Method**: `GET`
- **URL**: `/api/import/tables`
- **Headers**: `Authorization: Bearer <token>`, `Accept: application/json`

#### Example Response (200 OK)
```json
{
  "success": true,
  "message": "Authorized import tables retrieved successfully.",
  "data": [
    {
      "table": "patients",
      "label": "Patients",
      "description": "Patient records, demographics, and contact information",
      "tenant_column": "branch_id",
      "default_unique_by": "email",
      "supported_unique_keys": ["id", "email", "phone"],
      "supported_operations": ["insert", "skip_duplicates", "update_existing", "upsert"],
      "available_columns_count": 10
    },
    {
      "table": "treatments",
      "label": "Treatments & Procedures",
      "description": "Clinical dental procedures and standard pricing",
      "tenant_column": null,
      "default_unique_by": "procedure",
      "supported_unique_keys": ["id", "procedure", "treatment_code"],
      "supported_operations": ["insert", "skip_duplicates", "update_existing", "upsert"],
      "available_columns_count": 4
    }
  ]
}
```

---

### B. Get Table Columns Schema

Discovers table schema dynamically and returns metadata for importable columns. Auto-increment IDs, generated columns, timestamps, and sensitive passwords/tokens are excluded.

- **Method**: `GET`
- **URL**: `/api/import/tables/{table}/columns`
- **Headers**: `Authorization: Bearer <token>`, `Accept: application/json`

#### Example Response (200 OK)
```json
{
  "success": true,
  "message": "Table schema retrieved successfully.",
  "data": {
    "table": "patients",
    "label": "Patients",
    "description": "Patient records, demographics, and contact information",
    "tenant_column": "branch_id",
    "default_unique_by": "email",
    "supported_unique_keys": ["id", "email", "phone"],
    "columns": [
      {
        "name": "first_name",
        "label": "First Name",
        "type": "string",
        "raw_type": "varchar(255)",
        "nullable": false,
        "has_default": false,
        "default": null,
        "required": true,
        "is_writable": true,
        "is_auto_increment": false,
        "is_unique": false,
        "foreign_key": null
      },
      {
        "name": "email",
        "label": "Email",
        "type": "string",
        "raw_type": "varchar(255)",
        "nullable": true,
        "has_default": false,
        "default": null,
        "required": false,
        "is_writable": true,
        "is_auto_increment": false,
        "is_unique": true,
        "foreign_key": null
      }
    ]
  }
}
```

---

### C. Upload and Preview File

Inspects spreadsheet headers, proposes intelligent column mapping via exact matching and aliases, provides safe fuzzy suggestions, and previews sample rows without inserting records.

- **Method**: `POST`
- **URL**: `/api/import/preview`
- **Headers**: `Authorization: Bearer <token>`, `Content-Type: multipart/form-data`
- **Parameters**:
  - `file` (file, required): CSV, XLSX, or XLS file (max 100MB).
  - `table` (string, required): Destination table identifier (e.g. `patients`).
  - `sheet` (string, optional): Specific sheet name for Excel workbooks.

#### Example Response (200 OK)
```json
{
  "success": true,
  "message": "Import preview generated.",
  "data": {
    "import_id": "b18b4952-473d-4c31-92b0-84cbfbe0d3f2",
    "table": "patients",
    "headers": ["First Name", "Surname", "Email Address", "Phone Number", "Custom Note"],
    "mapping": {
      "First Name": "first_name",
      "Surname": "last_name",
      "Email Address": "email",
      "Phone Number": "phone"
    },
    "suggestions": {
      "Custom Note": [
        {
          "column": "history",
          "label": "History",
          "similarity": 72.5
        }
      ]
    },
    "unmapped_headers": ["Custom Note"],
    "missing_required_fields": [],
    "sample_rows": [
      {
        "First Name": "Jane",
        "Surname": "Doe",
        "Email Address": "jane.doe@example.com",
        "Phone Number": "+256701234567",
        "Custom Note": "Initial consultation"
      }
    ],
    "total_rows": 150
  }
}
```

#### Special Case: Patient Visit Logs (`DATE, NAME, CONTACT, PROCEDURE`)
When importing patient logs where headers are `DATE`, `NAME`, `CONTACT`, `PROCEDURE`:
1. **`DATE`**: Automatically recognized as the patient's **`last_visit`** date and normalized (e.g. `01/06/2025` &rarr; `2025-06-01`).
2. **`NAME`**: Proposes `first_name`. Full names (e.g. `Ludigo Stephane`) are automatically split into `first_name = "Ludigo"` and `last_name = "Stephane"`.
3. **`CONTACT`**: Automatically mapped to `phone`.
4. **`PROCEDURE`**: Automatically mapped to `history` to store procedures/clinical notes (e.g. `ortho`, `Extraction of 26`).
5. **Missing Fields**: Non-nullable database fields like `date_of_birth` automatically take configured fallbacks (e.g. `1999-01-01`), `gender` defaults to `Other`, and `branch_id` is automatically set to the authenticated clinic branch.

---

### D. Execute Import

Executes the import using either a preview job ID or a newly uploaded file with approved mapping.

- **Method**: `POST`
- **URL**: `/api/import/execute`
- **Headers**: `Authorization: Bearer <token>`, `Content-Type: application/json` or `multipart/form-data`
- **Body Parameters**:
  - `table` (string, required): Destination table identifier.
  - `import_id` (uuid, optional): Job ID returned by `/api/import/preview`.
  - `file` (file, optional): File upload if bypassing preview.
  - `mapping` (object, required): Map of spreadsheet headers to destination table columns:
    ```json
    {
      "First Name": "first_name",
      "Surname": "last_name",
      "Email Address": "email",
      "Phone Number": "phone"
    }
    ```
  - `mode` (string, optional, default: `insert`):
    - `insert`: Insert records directly.
    - `skip_duplicates`: Skip records if unique key exists.
    - `update_existing`: Update existing records matching unique key; skip new.
    - `upsert`: Update existing or insert new.
  - `update_key` (string, optional, default: configured table unique key, e.g. `email`): Key used for duplicate detection.
  - `error_mode` (string, optional, default: `partial`):
    - `partial`: Import valid rows, record failed rows in error log.
    - `atomic`: Roll back entire batch/transaction if any row fails validation or DB write.
  - `async` (boolean, optional): Set to `true` to force queued processing.

#### Example Synchronous Completed Response (200 OK)
```json
{
  "success": true,
  "message": "Import completed.",
  "data": {
    "job_id": "b18b4952-473d-4c31-92b0-84cbfbe0d3f2",
    "table": "patients",
    "status": "completed",
    "mode": "upsert",
    "total_rows": 150,
    "processed_rows": 150,
    "imported": 142,
    "updated": 6,
    "skipped": 0,
    "failed": 2,
    "progress_percentage": 100,
    "started_at": "2026-10-21T19:40:00Z",
    "completed_at": "2026-10-21T19:40:03Z",
    "errors_url": "http://localhost:8000/api/import/jobs/b18b4952-473d-4c31-92b0-84cbfbe0d3f2/errors/download"
  }
}
```

#### Example Asynchronous Queued Response (202 Accepted)
```json
{
  "success": true,
  "message": "Import queued for processing.",
  "data": {
    "job_id": "b18b4952-473d-4c31-92b0-84cbfbe0d3f2",
    "table": "patients",
    "status": "queued",
    "mode": "insert",
    "total_rows": 25000,
    "processed_rows": 0,
    "imported": 0,
    "updated": 0,
    "skipped": 0,
    "failed": 0,
    "progress_percentage": 0,
    "started_at": null,
    "completed_at": null,
    "errors_url": null
  }
}
```

---

### E. Import Job Status & Progress

Poll the status and real-time row counts of an active or completed import job.

- **Method**: `GET`
- **URL**: `/api/import/jobs/{id}`
- **Headers**: `Authorization: Bearer <token>`, `Accept: application/json`

#### Example Response (200 OK)
```json
{
  "success": true,
  "message": "Import job status retrieved.",
  "data": {
    "job_id": "b18b4952-473d-4c31-92b0-84cbfbe0d3f2",
    "table": "patients",
    "status": "processing",
    "mode": "insert",
    "error_mode": "partial",
    "total_rows": 10000,
    "processed_rows": 6500,
    "imported": 6480,
    "updated": 0,
    "skipped": 0,
    "failed": 20,
    "progress_percentage": 65,
    "started_at": "2026-10-21T19:40:00Z",
    "completed_at": null,
    "error_summary": null,
    "errors_url": "http://localhost:8000/api/import/jobs/b18b4952-473d-4c31-92b0-84cbfbe0d3f2/errors/download"
  }
}
```

---

### F. Retrieve Row Errors

Fetch paginated row-level error details for an import job.

- **Method**: `GET`
- **URL**: `/api/import/jobs/{id}/errors?page=1&per_page=25`
- **Headers**: `Authorization: Bearer <token>`, `Accept: application/json`

#### Example Response (200 OK)
```json
{
  "success": true,
  "message": "Import row errors retrieved.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "import_job_id": "b18b4952-473d-4c31-92b0-84cbfbe0d3f2",
        "row_number": 14,
        "source_column": "Email Address",
        "destination_field": "email",
        "error_code": "VALIDATION_FAILED",
        "error_message": "The Email field must be a valid email address.",
        "raw_data": {
          "First Name": "Invalid",
          "Surname": "EmailUser",
          "Email Address": "not-a-valid-email",
          "Phone Number": "+256000000"
        },
        "created_at": "2026-10-21T19:40:02Z"
      }
    ],
    "total": 1,
    "per_page": 25
  }
}
```

---

### G. Download Error Report (CSV)

Download a CSV file containing all failed rows and reason descriptions with **CSV Formula Injection Defense**.

- **Method**: `GET`
- **URL**: `/api/import/jobs/{id}/errors/download`
- **Headers**: `Authorization: Bearer <token>`
- **Response**: Streamed `text/csv` file download (`import_errors_{table}_{id}.csv`).

---

### H. Import History

Get paginated history of import jobs scoped to the authenticated user and branch.

- **Method**: `GET`
- **URL**: `/api/import/history?page=1&per_page=15&table=patients&status=completed`
- **Headers**: `Authorization: Bearer <token>`, `Accept: application/json`

---

## 4. Duplicate Handling Modes

| Mode | Behavior | Use Case |
|---|---|---|
| `insert` | Directly inserts rows into destination table. If a database unique constraint is violated, row is flagged as failed. | Clean master imports or tables without unique keys. |
| `skip_duplicates` | Queries destination table for existing record matching `update_key`. If found, increments `skipped_count` and moves to next row. | Incremental syncs where existing data must not be overwritten. |
| `update_existing` | Updates existing record matching `update_key`. If not found, skips the row without modifying DB. | Price list updates, bulk status adjustments. |
| `upsert` | If record exists matching `update_key`, updates it; otherwise, inserts new record. | Continuous data sync from external systems. |

---

## 5. Error Handling & Transaction Modes

- **Partial Mode (`error_mode: partial`, default)**:
  - Valid rows are committed to the database in batches.
  - Invalid rows are logged in `import_row_errors` with row number, column name, and human-readable explanation.
  - The job completes with accurate counts (`imported`, `updated`, `skipped`, `failed`).
- **Atomic Mode (`error_mode: atomic`)**:
  - The entire import is rolled back if any row fails validation or DB write.
  - Zero rows are persisted to the database.
  - Job status is marked as `failed` with the first error recorded.

---

## 6. Security & Formula Injection Protection

Spreadsheet applications (Excel, Google Sheets, LibreOffice) can execute arbitrary formula commands or DDE payloads if a cell begins with special formula trigger characters (`=`, `+`, `-`, `@`, `\t`, `\r`).

To defend against formula injection:
- Every field output in the downloadable CSV error report is inspected.
- If a value begins with `=,+,-,@,\t,\r`, it is prepended with a single quote (`'`), neutralizing executable formula evaluation in desktop spreadsheet applications.

---

## 7. Queue & Worker Setup

Large file imports are automatically or explicitly dispatched to the Laravel queue.

### Starting the Queue Worker

```bash
# Process jobs on the default queue
php artisan queue:work --tries=1 --timeout=1800
```

### Environment Configuration (`.env`)

```ini
QUEUE_CONNECTION=database
# Or redis in production
# QUEUE_CONNECTION=redis
```

---

## 8. Configuration & Customization Guide

### Adding a New Authorized Table

To authorize an existing database table for dynamic imports, open `config/import.php` and add an entry under `'tables'`:

```php
'suppliers' => [
    'model' => \App\Models\Supplier::class,
    'label' => 'Suppliers',
    'description' => 'Supplier directory and vendor accounts',
    'permission' => 'manage_inventory', // Permission required, or null for general access
    'tenant_column' => 'branch_id',     // Auto-inject authenticated user's branch_id
    'unique_by' => ['id', 'email', 'tax_number'], // Allowed unique keys for upsert
    'default_unique_by' => 'tax_number',
    'excluded_columns' => ['internal_notes'],      // Fields hidden from imports
    'defaults' => [
        'status' => 'Active',
    ],
    'custom_rules' => [
        'email' => 'nullable|email',
        'tax_number' => 'required|string|max:50',
    ],
],
```

### Configuring Custom Validation & Defaults

The importer merges database schema requirements with custom rules defined in `config/import.php`:
- `defaults`: Automatically fills in missing or empty values before validation.
- `custom_rules`: Overrides or supplements auto-detected rules (e.g. `in:Active,Inactive`, `numeric|min:0`).

---

## 9. End-to-End cURL Workflow Example

### Step 1: Preview a Spreadsheet
```bash
curl -X POST http://localhost:8000/api/import/preview \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@patients_sample.csv" \
  -F "table=patients"
```

### Step 2: Execute the Import
```bash
curl -X POST http://localhost:8000/api/import/execute \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "table": "patients",
    "import_id": "b18b4952-473d-4c31-92b0-84cbfbe0d3f2",
    "mode": "upsert",
    "update_key": "email",
    "error_mode": "partial",
    "mapping": {
      "First Name": "first_name",
      "Last Name": "last_name",
      "Email Address": "email",
      "Phone Number": "phone",
      "Gender": "gender",
      "Date of Birth": "date_of_birth"
    }
  }'
```

### Step 3: Poll Status (for async jobs)
```bash
curl -X GET http://localhost:8000/api/import/jobs/b18b4952-473d-4c31-92b0-84cbfbe0d3f2 \
  -H "Authorization: Bearer <TOKEN>"
```

### Step 4: Download Failed Rows Report
```bash
curl -X GET http://localhost:8000/api/import/jobs/b18b4952-473d-4c31-92b0-84cbfbe0d3f2/errors/download \
  -H "Authorization: Bearer <TOKEN>" \
  -o import_errors.csv
```

---

## 10. Known Limitations

1. **Excel Multi-Sheet Imports**:
   - By default, the active sheet or explicitly requested sheet (`sheet: "SheetName"`) is imported per job.
   - For multi-table combined workbooks containing different entities across distinct sheets, submit each sheet with its corresponding destination table.
2. **Formula Cell Evaluation**:
   - Excel formulas whose values were not calculated prior to export will resolve to their last cached calculated value.
3. **Database Native Types**:
   - Composite foreign keys spanning multiple columns should use single primary or unique identifiers for duplicate matching.
