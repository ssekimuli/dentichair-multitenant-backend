# Backend Migration API - Improvements Guide

## Overview

This guide describes improvements needed to the `MigrationController` to support the frontend's data migration enhancements.

## Phase 1: Enhanced Error Handling (CURRENT WORK)

### Current Response Format
```json
{
  "message": "Successfully imported data for patients",
  "count": 1500
}
```

### Improved Response Format
```json
{
  "success": true,
  "stats": {
    "total": 1500,
    "imported": 1497,
    "failed": 3,
    "skipped": 0,
    "duration": 5.23
  },
  "errors": [
    {
      "row": 152,
      "field": "email",
      "value": "invalid-email@",
      "reason": "Invalid email format (missing domain)"
    }
  ],
  "warnings": [
    "3 records with duplicate emails found and skipped"
  ],
  "import_id": "imp_8f2k3j4k3"
}
```

## Implementation Changes

### 1. Update processChunk() Return Value

**Before:**
```php
private function processChunk($section, $chunk)
{
    // Process silently
    // No return value
}
```

**After:**
```php
private function processChunk($section, $chunk)
{
    $result = [
        'imported' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
        'warnings' => []
    ];

    // Process with error tracking
    foreach ($chunk as $item) {
        $rowNumber = $item['rowNumber'];
        $record = $item['data'];
        
        // Validate and track errors
        if (validation_fails) {
            $result['errors'][] = [
                'row' => $rowNumber,
                'field' => 'fieldname',
                'value' => $value,
                'reason' => 'Validation message'
            ];
            $result['failed']++;
            continue;
        }
        
        // Process successfully
        $result['imported']++;
    }

    return $result;
}
```

### 2. Add Validation Rules per Section

**Employees:**
- Email: Required, valid format, unique
- Name: Required
- Role: Must exist in role_permissions table
- Hire date: Valid date format, not future

**Patients:**
- Email or Phone: At least one required
- First Name: Required
- Date of Birth: Valid date, not future (age < 150)
- Gender: Must be Male/Female/Other

**Appointments:**
- Patient email/phone: Required (for lookup)
- Doctor email/phone: Required (for lookup)
- Date: Valid date format
- Time: Valid time format (HH:MM)

**Clinical:**
- Patient email: Required
- Doctor email: Required
- Visit date: Valid date, not future
- Cost: Non-negative number

**Financial:**
- Patient email: Required
- Amount: Positive number, valid format
- Date: Valid date format

### 3. Duplicate Detection

Track and warn about:
- Duplicate emails (employees, patients, financial)
- Duplicate phone numbers (patients)
- Duplicate appointment dates/times
- Duplicate invoices

Example warning:
```
"3 records with duplicate emails were skipped (existing records found)"
```

### 4. Better Error Messages

Include row number and specific reason:
```
Row 152: email field - "Invalid email format (missing domain)"
Row 253: phone field - "Phone number too short (minimum 10 digits)"
```

## API Endpoints - Phase 1

### POST /migrate (Updated)

**Request:**
```
POST /api/migrate
Content-Type: multipart/form-data

file: [CSV file]
section: "patients"
```

**Successful Response (200 OK):**
```json
{
  "success": true,
  "stats": {
    "total": 1500,
    "imported": 1497,
    "failed": 3,
    "skipped": 0,
    "duration": 5.23
  },
  "errors": [],
  "warnings": [],
  "import_id": "imp_abc123"
}
```

**Error Response (400 Bad Request):**
```json
{
  "success": false,
  "stats": {
    "total": 1500,
    "imported": 500,
    "failed": 1000,
    "skipped": 0,
    "duration": 3.15
  },
  "errors": [
    {
      "row": 152,
      "field": "email",
      "value": "invalid@",
      "reason": "Invalid email format"
    }
  ],
  "warnings": [],
  "import_id": "imp_abc123"
}
```

## Implementation Steps

### Step 1: Modify migrate() Method
1. Add `$importId` generation
2. Track start time with `microtime(true)`
3. Initialize arrays: `$errors`, `$warnings`, `$imported`, `$failed`, `$skipped`
4. Track row numbers while reading CSV
5. Collect errors from processChunk()
6. Calculate duration and return proper response

### Step 2: Update processChunk() Methods
For each section (employees, patients, appointments, clinical, financial):
1. Add error tracking at start
2. Validate required fields
3. Validate data formats (email, date, numbers)
4. Check for duplicates and log warnings
5. Return result object with counts and errors

### Step 3: Add Validation Helper Methods

```php
private function validateEmail($email, $row)
{
    if (empty($email)) {
        return ['valid' => false, 'reason' => 'Email is required'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'reason' => 'Invalid email format'];
    }
    if (strlen($email) > 255) {
        return ['valid' => false, 'reason' => 'Email too long (max 255 characters)'];
    }
    return ['valid' => true];
}

private function validateDate($date, $row, $allowFuture = false)
{
    if (empty($date)) {
        return ['valid' => false, 'reason' => 'Date is required'];
    }
    
    $parsed = strtotime($date);
    if ($parsed === false) {
        return ['valid' => false, 'reason' => "Invalid date format: {$date}"];
    }
    
    if (!$allowFuture && $parsed > time()) {
        return ['valid' => false, 'reason' => 'Date cannot be in the future'];
    }
    
    return ['valid' => true];
}

private function validatePhone($phone, $row)
{
    if (empty($phone)) {
        return ['valid' => false, 'reason' => 'Phone is required'];
    }
    
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 10) {
        return ['valid' => false, 'reason' => 'Phone number too short (minimum 10 digits)'];
    }
    if (strlen($digits) > 20) {
        return ['valid' => false, 'reason' => 'Phone number too long (maximum 20 digits)'];
    }
    
    return ['valid' => true];
}
```

### Step 4: Add Duplicate Detection

```php
private function checkDuplicate($section, $field, $value, $rowNumber, &$errors, &$warnings)
{
    $query = null;
    $table = null;
    $checkField = null;

    if ($section === 'employees' && $field === 'email') {
        $query = DB::table('employees')->where('email', strtolower(trim($value)))->first();
        if ($query) {
            $warnings[] = "Row {$rowNumber}: Duplicate email found (employee ID: {$query->id})";
            return true;
        }
    } elseif ($section === 'patients' && $field === 'email') {
        $query = DB::table('patients')->where('email', strtolower(trim($value)))->first();
        if ($query) {
            $warnings[] = "Row {$rowNumber}: Duplicate email found (patient ID: {$query->id})";
            return true;
        }
    }
    
    return false;
}
```

## Testing

### Test Cases

1. **Valid CSV**
   - Upload valid file
   - Verify all records imported
   - Check stats are correct
   - Errors should be empty

2. **Invalid Email**
   - Upload CSV with "invalid-email@"
   - Should show error for that row
   - Other valid rows should still import

3. **Missing Required Field**
   - Upload CSV missing email for patients
   - Should show error and skip that row
   - Other rows should import

4. **Duplicate Email**
   - Upload CSV with email that already exists
   - Should skip duplicate
   - Show warning message

5. **Invalid Date**
   - Upload CSV with invalid date "2025-13-45"
   - Should show error for that row
   - Other rows should import

6. **Mixed Errors**
   - Upload CSV with multiple types of errors
   - Should show all errors with row numbers
   - Statistics should reflect actual counts

### Sample Test CSV (Patients)

```csv
first_name,last_name,email,phone,date_of_birth,gender
John,Doe,john@example.com,1234567890,1990-01-15,Male
Jane,Smith,jane@example.com,0987654321,1992-05-20,Female
Bob,Johnson,invalid-email,5551234567,2025-01-01,Male
Alice,Williams,alice@example.com,9876543210,1988-03-10,Female
```

Expected Result:
```json
{
  "success": false,
  "stats": {
    "total": 4,
    "imported": 3,
    "failed": 1,
    "skipped": 0,
    "duration": 0.45
  },
  "errors": [
    {
      "row": 4,
      "field": "email",
      "value": "invalid-email",
      "reason": "Invalid email format"
    },
    {
      "row": 4,
      "field": "date_of_birth",
      "value": "2025-01-01",
      "reason": "Birth date cannot be in future"
    }
  ]
}
```

## Phase 2: Additional Endpoints (24 Hours)

### POST /migrate/validate

Validate CSV before upload without importing.

**Request:**
```
POST /api/migrate/validate
file: [CSV file]
section: "patients"
```

**Response:**
```json
{
  "valid": false,
  "issues": [
    {
      "field": "email",
      "row": 152,
      "reason": "Invalid email format",
      "severity": "error"
    },
    {
      "field": "date_of_birth",
      "row": 253,
      "reason": "Birth date in future",
      "severity": "error"
    }
  ],
  "stats": {
    "total_rows": 1500,
    "valid_rows": 1498,
    "invalid_rows": 2
  }
}
```

### POST /migrate/check-duplicates

Check for duplicate records before importing.

**Request:**
```
POST /api/migrate/check-duplicates
file: [CSV file]
section: "patients"
duplicateFields: ["email", "phone"]
```

**Response:**
```json
{
  "duplicates": [
    {
      "row": 45,
      "matchedRecordId": "patient_123",
      "matchedFields": ["email"],
      "existingData": {
        "id": "patient_123",
        "first_name": "John",
        "email": "john@example.com"
      }
    }
  ],
  "stats": {
    "total": 1500,
    "duplicates": 1,
    "unique": 1499
  }
}
```

### GET /migrate/:import_id/progress

Track import progress for large files.

**Response:**
```json
{
  "status": "processing",
  "processed": 750,
  "total": 1500,
  "percentage": 50,
  "eta_seconds": 45,
  "current_row": 750
}
```

## Performance Considerations

1. **Chunking:** Process 1000 records at a time
2. **Validation:** Cache lookups (roles, branches) during chunk processing
3. **Duplicates:** Use indexed queries for duplicate detection
4. **Error Limit:** Return first 100 errors to prevent huge responses
5. **Time Limit:** Use `set_time_limit(0)` for large files

## Security

1. **File Upload:** Validate file type and size before processing
2. **SQL Injection:** Use parameterized queries (already using DB::table)
3. **Path Traversal:** Don't allow arbitrary file paths
4. **Email Validation:** Use proper email validation
5. **Rate Limiting:** Consider rate limiting import endpoint

## Database Optimization

Add indexes for duplicate detection:
```sql
ALTER TABLE employees ADD INDEX idx_email (email);
ALTER TABLE patients ADD INDEX idx_email (email);
ALTER TABLE patients ADD INDEX idx_phone (phone);
ALTER TABLE appointments ADD INDEX idx_patient_date (patient_id, date);
```

## Migration Timeline

- **Week 1:** Implement Phase 1 (enhanced responses, error tracking)
- **Week 2:** Implement Phase 2 (validation, duplicate detection)
- **Week 3:** Testing and optimization
- **Week 4:** Deployment and monitoring

## Questions & Notes

- Consider transaction support for atomic imports
- Consider progress tracking with job queue for very large files
- Consider allowing rollback of failed imports
- Consider logging all imports for audit trail

