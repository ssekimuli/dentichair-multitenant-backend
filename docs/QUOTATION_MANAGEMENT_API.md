# DentiChair Quotation Management Module — REST API & Architecture Documentation

## 1. Architectural Overview

The **Quotation Management Module** is a centralized, production-ready REST API module for DentiChair Dental Practice Management System. It manages the complete quotation lifecycle for two customer entities:
1. **Patients** (`App\Models\Patient`)
2. **Laboratory Clients** (`App\Models\Client`)

Both customer types share the unified quotation lifecycle, approval workflows, PDF generation, notification delivery, and conversion into the existing **Billing/Invoice module**.

### Key Tenets
- **Single Quotation Engine**: Both patient treatments and dental lab appliance/prosthetic orders use the same quotation models, tables, services, and endpoints.
- **Strict Customer Isolation**: A quotation belongs to either a patient (`patient_id`) OR a laboratory client (`lab_client_id`), never both.
- **Tenant Isolation**: Multi-tenant data isolation follows the existing DentiChair `branch_id` convention across all tables and models. No `clinic_id` or `workspace_id` columns were introduced.
- **Financial Precision**: All calculations are executed server-side using 2-decimal rounded precision; client totals are never trusted.
- **Concurrency & Idempotency**: Invoice conversion utilizes row-level locking (`lockForUpdate()`) and atomic database transactions (`DB::transaction`) to guarantee that an approved quotation can only ever generate a single invoice.
- **Security & Privacy**: Public customer approval links use cryptographically random tokens stored as SHA-256 hashes with expiration and one-time decision enforcement. Sensitive clinic notes and internal identifiers are shielded from the public API.

---

## 2. Database Schema & Models

### Tables Created:
1. `quotations`
   - `id`: Primary key
   - `quotation_number`: Unique alphanumeric quote identifier (e.g., `QUO-202611-1-A1B2C3`)
   - `customer_type`: `enum('patient', 'lab_client')`
   - `patient_id`: Foreign key referencing `patients.id` (nullable)
   - `lab_client_id`: Foreign key referencing `clients.id` (nullable)
   - `dentist_id`: Foreign key referencing `employees.id` (nullable)
   - `lab_order_id`: Foreign key referencing `laboratory_orders.id` (nullable)
   - `branch_id`: Foreign key referencing `branches.id` (tenant boundary)
   - `source_type`: `enum('manual', 'treatment_plan', 'lab_order', 'mixed')`
   - `source_id`: Reference identifier for imported source record
   - `quotation_date`: Issue date
   - `valid_until`: Expiry date
   - `currency`: Currency code (e.g. `USD`, `KES`)
   - `subtotal`: Sum of item lines before overall discount/tax
   - `discount_type`: `percentage`, `fixed`, or `none`
   - `discount_amount`: Total discount amount applied
   - `tax_rate`: Quotation tax rate percentage
   - `tax_amount`: Calculated tax amount
   - `total`: Final grand total
   - `status`: `enum('draft', 'sent', 'approved', 'rejected', 'expired', 'converted', 'cancelled')`
   - `notes`: Internal or operational notes
   - `terms_conditions`: Terms and conditions displayed on quotation
   - `expected_delivery_date`: Estimated completion or delivery date
   - `approved_at`: Timestamp of approval
   - `rejected_at`: Timestamp of rejection
   - `converted_invoice_id`: Foreign key referencing `invoices.id`
   - `original_quotation_id`: Foreign key referencing `quotations.id` (for revision hierarchy)
   - `revision_number`: Incrementing integer tracking revisions
   - `created_by` / `updated_by`: Auditing foreign keys referencing `employees.id`
   - `deleted_at`: Soft delete timestamp
   - `created_at` / `updated_at`: Timestamps

2. `quotation_items`
   - `id`: Primary key
   - `quotation_id`: Foreign key referencing `quotations.id` (cascade on delete)
   - `service_id`: Optional procedure or lab service ID
   - `treatment_plan_item_id`: Reference to imported treatment plan item
   - `lab_order_item_id`: Reference to imported lab order
   - `item_type`: `procedure`, `lab_service`, `appliance`, `material`, `custom`
   - `description`: Item description
   - `tooth_number`: Specific tooth number (e.g., `#14`) or tooth range (e.g., `11-13`)
   - `material`: Dental material (e.g., `Zirconia`, `E-Max`, `Titanium`)
   - `shade`: Shade/color matching (e.g., `A2`, `B1`, `BL1`)
   - `quantity`: Quantity integer (minimum 1)
   - `unit_price`: Unit price decimal
   - `discount_type` / `discount_amount`: Line item discount
   - `tax_rate` / `tax_amount`: Line item tax
   - `total`: Line total
   - `expected_delivery_date`: Item-specific delivery target
   - `special_instructions`: Clinical notes / technician directives
   - `sort_order`: Display sorting sequence

3. `quotation_approvals`
   - Records formal approval and rejection decisions with audit metadata (`approval_method`, `decision`, `approved_by`, `customer_name`, `customer_email`, `customer_phone`, `ip_address`, `user_agent`, `approved_at`).

4. `quotation_status_histories`
   - Immutable audit trail recording each status transition (`old_status`, `new_status`, `changed_by`, `change_source`, `notes`, `created_at`).

5. `quotation_access_tokens`
   - Secure customer approval tokens stored as SHA-256 hashes with `expires_at`, `used_at`, and `revoked_at`.

6. `invoices` (Extension):
   - Added backward-compatible `client_id` (foreign key to `clients`, nullable) alongside existing `patient_id` and `patient_name`.

---

## 3. Quotation Status State Machine

```
               ┌─────────────┐
               │    Draft    ├────────────────┐
               └──────┬──────┘                │
                      │ send                  │
                      ▼                       │
               ┌─────────────┐                │
      ┌────────┤    Sent     ├────────┐       │
      │        └──────┬──────┘        │       │
      │ reject        │ approve       │       │ cancel
      ▼               ▼               │       │
┌───────────┐  ┌─────────────┐        │       │
│ Rejected  │  │  Approved   │        ▼       │
└─────┬─────┘  └──────┬──────┘     ┌───────┐  │
      │               │ convert    │Expired│  │
      │ revise        ▼            └───┬───┘  │
      │        ┌─────────────┐         │      │
      └───────>│  Converted  │         │ renew│
               └─────────────┘         ▼      ▼
                                  ┌─────────────┐
                                  │  Cancelled  │
                                  └─────────────┘
```

### Business Rules:
1. **Draft**: Editable. Can be dispatched (`send`) or cancelled.
2. **Sent**: Frozen against direct edits. Awaiting decision. Can be approved, rejected, expired, or cancelled.
3. **Approved**: Customer or staff recorded consent. Can be converted into an invoice. Cannot be silently modified.
4. **Rejected**: Declined by customer. Cannot be converted to invoice. Can be revised.
5. **Expired**: Validity date elapsed. Cannot be approved without renewal.
6. **Converted**: Successfully generated an Invoice. Terminal status. Cannot be converted again.
7. **Cancelled**: Inactive. Cannot be converted.

---

## 4. REST API Endpoint Reference

All routes are available under `/api/v1/` and `/api/`.

### A. General Quotation Management

#### 1. List Quotations
- **Endpoint**: `GET /api/v1/quotations`
- **Auth**: Required (`auth:sanctum`)
- **Query Parameters**:
  - `search` (string): Search by quote number or customer name.
  - `customer_type` (`patient` | `lab_client`): Filter by customer entity.
  - `patient_id` (int): Filter by specific patient.
  - `lab_client_id` (int): Filter by specific lab client.
  - `dentist_id` (int): Filter by doctor.
  - `status` (`draft` | `sent` | `approved` | `rejected` | `expired` | `converted` | `cancelled`).
  - `source_type` (`manual` | `treatment_plan` | `lab_order` | `mixed`).
  - `date_from` / `date_to` (`YYYY-MM-DD`).
  - `valid_until_from` / `valid_until_to` (`YYYY-MM-DD`).
  - `per_page` (int, default 15).
  - `sort_by` (`quotation_date`, `valid_until`, `total`, `created_at`, `status`).
  - `sort_order` (`asc` | `desc`).

#### 2. Create Manual Quotation
- **Endpoint**: `POST /api/v1/quotations`
- **Auth**: Required (`auth:sanctum`)
- **Request Body (Patient Example)**:
```json
{
  "customer_type": "patient",
  "patient_id": 1,
  "dentist_id": 2,
  "quotation_date": "2026-11-01",
  "valid_until": "2026-11-30",
  "discount_type": "percentage",
  "discount_amount": 10,
  "tax_rate": 5,
  "notes": "Ceramic crown options discussed.",
  "items": [
    {
      "description": "Composite Resin Restoration",
      "tooth_number": "14",
      "quantity": 1,
      "unit_price": 120.00
    },
    {
      "description": "Porcelain Crown",
      "tooth_number": "14",
      "quantity": 1,
      "unit_price": 600.00
    }
  ]
}
```
- **Response**: `201 Created`

#### 3. Get Quotation Details
- **Endpoint**: `GET /api/v1/quotations/{id}`
- **Auth**: Required (`auth:sanctum`)
- **Response**: Returns complete quotation, customer object, dentist object, items, status history, approvals, and converted invoice if present.

#### 4. Update Draft Quotation
- **Endpoint**: `PUT /api/v1/quotations/{id}`
- **Auth**: Required (`auth:sanctum`)
- **Rule**: Allowed only when `status === 'draft'`. Returns `422 Unprocessable Entity` if quote is approved or converted.

#### 5. Delete or Cancel Quotation
- **Endpoint**: `DELETE /api/v1/quotations/{id}`
- **Auth**: Required (`auth:sanctum`)
- **Rule**: Draft quotations are soft-deleted. Approved or converted documents cannot be deleted.

---

### B. Patient Quotation Workflows

#### 1. Retrieve Patient Quotations
- **Endpoint**: `GET /api/v1/patients/{patientId}/quotations`
- **Auth**: Required

#### 2. Create Quotation from Treatment Plans
- **Endpoint**: `POST /api/v1/patients/{patientId}/quotations/from-treatment-plan`
- **Auth**: Required
- **Payload**:
```json
{
  "treatment_plan_item_ids": [101, 102],
  "dentist_id": 3,
  "valid_until": "2026-12-01",
  "discount_type": "fixed",
  "discount_amount": 50.00,
  "additional_items": [
    {
      "description": "Chlorhexidine Rinse & Post-Op Kit",
      "quantity": 1,
      "unit_price": 25.00
    }
  ]
}
```

---

### C. Laboratory Client Quotation Workflows

#### 1. Retrieve Lab Client Quotations
- **Endpoint**: `GET /api/v1/lab-clients/{labClientId}/quotations`
- **Auth**: Required

#### 2. Retrieve Lab Order Quotations
- **Endpoint**: `GET /api/v1/lab-orders/{labOrderId}/quotations`
- **Auth**: Required

#### 3. Create Quotation from Lab Order
- **Endpoint**: `POST /api/v1/lab-orders/{labOrderId}/quotations` (or `POST /api/v1/quotations/from-lab-order`)
- **Auth**: Required
- **Payload**:
```json
{
  "lab_order_id": 45,
  "expected_delivery_date": "2026-11-15",
  "notes": "Custom shade guide provided.",
  "additional_items": [
    {
      "description": "Titanium Custom Abutment",
      "quantity": 1,
      "unit_price": 180.00
    }
  ]
}
```

---

### D. Quotation Dispatch & Notifications

#### Send Quotation
- **Endpoint**: `POST /api/v1/quotations/{id}/send`
- **Auth**: Required
- **Payload**:
```json
{
  "channels": ["email", "whatsapp", "sms"],
  "message": "Hello, please review your dental quotation."
}
```
- **Actions**:
  - Generates secure cryptographically random public approval token.
  - Automatically updates quote status from `draft` to `sent`.
  - Dispatches email via Laravel Mail.
  - Dispatches WhatsApp message via `NotificationService::sendWhatsApp`.
  - Dispatches SMS message via `NotificationService::sendSms`.
  - Returns `approval_url` and channel delivery status.

---

### E. Staff Approval and Rejection

#### 1. Staff Record Approval
- **Endpoint**: `POST /api/v1/quotations/{id}/approve`
- **Auth**: Required
- **Payload**:
```json
{
  "customer_name": "Michael Scott",
  "approval_method": "clinic_staff",
  "notes": "Patient approved in clinic after consultation."
}
```

#### 2. Staff Record Rejection
- **Endpoint**: `POST /api/v1/quotations/{id}/reject`
- **Auth**: Required
- **Payload**:
```json
{
  "reason": "Customer postponed elective restorative treatment."
}
```

---

### F. Public Customer Online Approval Portal

These endpoints are public (no authentication required) and rate-limited.

#### 1. View Public Quotation
- **Endpoint**: `GET /api/v1/public/quotations/{token}`
- **Security**: Validates unhashed token against SHA-256 hash in DB, checks token expiry, verifies quotation validity. Shields all internal clinic notes and database IDs.

#### 2. Public Approval with Consent
- **Endpoint**: `POST /api/v1/public/quotations/{token}/approve`
- **Payload**:
```json
{
  "customer_name": "Michael Scott",
  "customer_email": "michael@dundermifflin.com",
  "consent_confirmed": true,
  "notes": "Approved online."
}
```
- **Actions**:
  - Validates `consent_confirmed` is true.
  - Marks token as used (single-use enforcement).
  - Transitions quote status to `approved`.
  - Records audit trail with customer IP address and user agent.

#### 3. Public Rejection
- **Endpoint**: `POST /api/v1/public/quotations/{token}/reject`
- **Payload**:
```json
{
  "reason": "Decided on alternative treatment."
}
```

---

### G. Invoice Conversion Integration

#### Convert Approved Quotation into Invoice
- **Endpoint**: `POST /api/v1/quotations/{id}/convert`
- **Auth**: Required
- **Execution Flow**:
  1. Transaction begins (`DB::transaction`).
  2. Row-level lock acquired on quotation (`lockForUpdate`).
  3. Verifies `branch_id` matches authenticated user's branch.
  4. Verifies status is `approved`.
  5. Verifies `converted_invoice_id` is null (strictly prevents duplicate invoices).
  6. Generates `Invoice`:
     - Patient quotation: Sets `patient_id`, `patient_name`, `category = 'procedure-income'`.
     - Lab quotation: Sets `patient_id = null`, `client_id = quotation->lab_client_id`, `patient_name = client->name`, `category = 'lab-income'`.
     - Preserves subtotal, discounts, taxes, and grand total.
     - Sets initial status to `Pending` with `paid_amount = 0.00`.
     - Auto-generates receipt number (e.g. `REC-XXXXXXXX`).
  7. Maps quotation items to `InvoiceTreatment` records preserving procedure name, tooth numbers, materials, shades, quantities, and prices.
  8. If linked to `LaboratoryOrder`, sets `laboratory_orders.invoice_id = invoice.id`.
  9. Updates quotation: `status = 'converted'`, `converted_invoice_id = invoice.id`.
  10. Records status history & ActivityLog.
  11. Transaction commits and returns generated invoice.

---

### H. Revisions, Renewals & PDF

#### 1. Renew Expired Quotation
- **Endpoint**: `POST /api/v1/quotations/{id}/renew`
- **Payload**:
```json
{
  "valid_until": "2026-12-15"
}
```

#### 2. Revise Quotation
- **Endpoint**: `POST /api/v1/quotations/{id}/revise`
- **Payload**:
```json
{
  "notes": "Revision 1 with 10% promotional discount",
  "discount_type": "percentage",
  "discount_amount": 10
}
```
- **Behavior**: Creates a new quotation linked to `original_quotation_id` with incremented `revision_number` (e.g. `QUO-202611-1-ABC123-R1`). Leaves original quotation unchanged.

#### 3. Cancel Quotation
- **Endpoint**: `POST /api/v1/quotations/{id}/cancel`

#### 4. Download PDF / Print View
- **Endpoint**: `GET /api/v1/quotations/{id}/pdf`
  - Returns `Content-Type: application/pdf` with compliant `%PDF-1.4` binary stream.
- **Endpoint**: `GET /api/v1/quotations/{id}/pdf?format=html`
  - Returns printable HTML view with clinic branding, tooth numbers, items, and financial summary.

#### 5. Audit History
- **Endpoint**: `GET /api/v1/quotations/{id}/history`
  - Returns complete status transition history and recorded approval/rejection decisions.

