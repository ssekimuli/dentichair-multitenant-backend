# Lab Orders API Documentation

## Overview
Complete API documentation for Laboratory Orders management with integrated Lab Prices support.

---

## Endpoints

### 1. Get Lab Orders
**Endpoint:** `GET /api/management/lab-orders`

**Query Parameters:**
- `page` (integer, optional) - Page number for pagination
- `per_page` (integer, optional) - Items per page
- `search` (string, optional) - Search by patient name, item, or lab
- `status` (string, optional) - Filter by status
- `client_id` (integer, optional) - Filter by client
- `doctor_id` (integer, optional) - Filter by doctor
- `sent_date_from` (date, optional) - Filter from date
- `sent_date_to` (date, optional) - Filter to date
- `due_date_from` (date, optional) - Filter from due date
- `due_date_to` (date, optional) - Filter to due date

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "client_id": 1,
      "patient_name": "John Doe",
      "lab": "Premier Lab",
      "item": "Crown",
      "tooth": 11,
      "doctor_id": 2,
      "status": "In Production",
      "sent_date": "2026-08-28",
      "due_date": "2026-09-04",
      "received_date": null,
      "shade": "A1",
      "material": "Porcelain",
      "instructions": "High-strength, no adjustments",
      "cost": 5000,
      "branch_id": 1,
      "invoice_id": null,
      "lab_service_id": 1,
      "applied_price": 5000,
      "created_at": "2026-08-28T10:30:00Z",
      "updated_at": "2026-08-28T10:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 100
  }
}
```

---

### 2. Create Lab Order
**Endpoint:** `POST /api/management/lab-orders`

**Request Body:**
```json
{
  "clientId": 1,
  "patientName": "John Doe",
  "lab": "Premier Lab",
  "item": "Crown",
  "tooth": 11,
  "status": "Draft",
  "sentDate": "2026-08-28",
  "dueDate": "2026-09-04",
  "branchId": 1,
  "receivedDate": null,
  "shade": "A1",
  "material": "Porcelain",
  "instructions": "High-strength, no adjustments",
  "cost": 5000,
  "doctorId": 2,
  "labServiceId": 1,
  "appliedPrice": 5000
}
```

**Required Fields:**
- `clientId`
- `lab`
- `item`
- `status`
- `sentDate`
- `dueDate`
- `branchId`

**Optional Fields:**
- `patientName` - Auto-filled from client name if not provided
- `receivedDate`
- `shade`
- `material`
- `instructions`
- `cost`
- `doctorId`
- `labServiceId` - Links to configured lab service
- `appliedPrice` - Auto-fetched from lab service if not provided

**Response (201):**
```json
{
  "id": 1,
  "client_id": 1,
  "patient_name": "John Doe",
  "lab": "Premier Lab",
  "item": "Crown",
  "tooth": 11,
  "doctor_id": 2,
  "status": "Draft",
  "sent_date": "2026-08-28",
  "due_date": "2026-09-04",
  "received_date": null,
  "shade": "A1",
  "material": "Porcelain",
  "instructions": "High-strength, no adjustments",
  "cost": 5000,
  "branch_id": 1,
  "invoice_id": null,
  "lab_service_id": 1,
  "applied_price": 5000,
  "created_at": "2026-08-28T10:30:00Z",
  "updated_at": "2026-08-28T10:30:00Z",
  "client": {...},
  "branch": {...}
}
```

**Error Responses:**
- `422` - Validation error
- `500` - Server error

---

### 3. Update Lab Order Details (NEW)
**Endpoint:** `PUT /api/management/lab-orders/{id}`

**URL Parameters:**
- `id` (integer, required) - Lab Order ID

**Request Body (all fields optional):**
```json
{
  "clientId": 1,
  "patientName": "Jane Doe",
  "lab": "Standard Lab",
  "item": "Bridge",
  "tooth": 12,
  "status": "In Production",
  "sentDate": "2026-08-28",
  "dueDate": "2026-09-05",
  "receivedDate": null,
  "shade": "A2",
  "material": "Zirconia",
  "instructions": "Updated instructions",
  "cost": 8000,
  "doctorId": 3,
  "labServiceId": 2,
  "appliedPrice": 8000,
  "invoiceId": 5
}
```

**Features:**
- All fields optional (use `sometimes` validation)
- `labServiceId` links to lab service
- `appliedPrice` auto-fetches if service provided
- Service price can be overridden manually
- Applied price never changes after order creation (audit trail)
- Only updates provided fields

**Response (200):**
```json
{
  "id": 1,
  "client_id": 1,
  "patient_name": "Jane Doe",
  "lab": "Standard Lab",
  "item": "Bridge",
  "tooth": 12,
  "doctor_id": 3,
  "status": "In Production",
  "sent_date": "2026-08-28",
  "due_date": "2026-09-05",
  "received_date": null,
  "shade": "A2",
  "material": "Zirconia",
  "instructions": "Updated instructions",
  "cost": 8000,
  "branch_id": 1,
  "invoice_id": 5,
  "lab_service_id": 2,
  "applied_price": 8000,
  "created_at": "2026-08-28T10:30:00Z",
  "updated_at": "2026-08-28T11:45:00Z",
  "client": {...},
  "branch": {...},
  "labService": {...}
}
```

**Error Responses:**
- `404` - Lab order not found
- `422` - Validation error
- `500` - Server error

---

### 4. Update Lab Order Status
**Endpoint:** `PATCH /api/management/lab-orders/{id}/status`

**URL Parameters:**
- `id` (integer, required) - Lab Order ID

**Request Body:**
```json
{
  "status": "In Production"
}
```

**Valid Status Values:**
- `Draft`
- `Received`
- `In Production`
- `Ready for Delivery`
- `Delivered`
- `Cancelled`

**Response (200):**
```json
{
  "id": 1,
  "status": "In Production",
  "updated_at": "2026-08-28T11:50:00Z",
  ...
}
```

---

### 5. Send Lab Order
**Endpoint:** `POST /api/management/lab-orders/{id}/send`

**URL Parameters:**
- `id` (integer, required) - Lab Order ID

**Request Body:**
```json
{
  "sentDate": "2026-08-28"
}
```

**Response (200):**
```json
{
  "id": 1,
  "status": "Sent",
  "sent_date": "2026-08-28",
  "updated_at": "2026-08-28T11:55:00Z",
  ...
}
```

---

## Lab Service Integration

### Auto-Price Fetching
When creating or updating a lab order with `labServiceId`:

1. **Create with Service:**
```json
{
  "labServiceId": 4,
  // appliedPrice can be omitted - will auto-fetch
  "appliedPrice": null
}
```
→ Backend fetches active price for service 4

2. **Create with Service + Override:**
```json
{
  "labServiceId": 4,
  "appliedPrice": 5500  // Override default price
}
```
→ Uses provided price instead of active price

3. **Create without Service (Backward Compatible):**
```json
{
  "labServiceId": null,
  "appliedPrice": 5000  // Manual price entry
}
```
→ No service linked, price set manually

### Applied Price Behavior
- **Set Once:** `applied_price` is set when order is created
- **Immutable:** Cannot be changed (maintains audit trail)
- **Historical:** Different from current service price
- **Tracking:** Shows what price was used when order was created

**Example:** Service originally cost 5000, now costs 6000
- Old orders still show `applied_price: 5000`
- New orders will get `applied_price: 6000`

---

## Activity Logging

All lab order operations are logged:

```
Action: Lab Order Created
Description: Lab order for John Doe was created.
Metadata: { lab_order_id: 1, client_id: 1 }

Action: Lab Order Updated
Description: Lab order #1 was updated.
Metadata: { lab_order_id: 1 }
```

---

## Validation Rules

### Create Lab Order
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| clientId | integer | Yes | Must exist in clients table |
| patientName | string | No | Max 255 chars |
| lab | string | Yes | Required |
| item | string | Yes | Required |
| tooth | integer | No | Valid tooth number |
| status | string | Yes | One of status values |
| sentDate | date | Yes | Valid date format |
| dueDate | date | Yes | Valid date format |
| branchId | integer | Yes | Must exist in branches table |
| receivedDate | date | No | Valid date format |
| shade | string | No | Max 255 chars |
| material | string | No | Max 255 chars |
| instructions | string | No | Text |
| cost | numeric | No | Positive number |
| doctorId | integer | No | Must exist in employees table |
| labServiceId | integer | No | Must exist in lab_services table |
| appliedPrice | numeric | No | Non-negative decimal |

### Update Lab Order
All fields are `sometimes` (optional), same validation rules apply when provided

---

## Response Codes

| Code | Meaning |
|------|---------|
| 200 | Success (GET, PUT, PATCH, POST with data response) |
| 201 | Created (POST with object response) |
| 204 | No Content (DELETE) |
| 400 | Bad Request |
| 404 | Not Found |
| 422 | Unprocessable Entity (Validation Error) |
| 500 | Server Error |

---

## Backward Compatibility

✅ **Backward Compatible:** All existing lab orders work without changes
- `labServiceId` = NULL → No service linked
- `appliedPrice` = NULL → Manual price only
- Existing orders unaffected by new fields

---

## Frontend Integration

### Using the API

#### Create Lab Order with Service
```typescript
import { createLabOrder } from '@/lib/api';

await createLabOrder({
  clientId: 1,
  labServiceId: 4,
  lab: 'Premier Lab',
  item: 'Crown',
  status: 'Draft',
  sentDate: '2026-08-28',
  dueDate: '2026-09-04',
  branchId: 1,
  // appliedPrice auto-fetched from service
});
```

#### Update Lab Order
```typescript
import { updateLabOrder } from '@/lib/api';

await updateLabOrder(1, {
  status: 'In Production',
  receivedDate: '2026-08-30',
  labServiceId: 5, // Change service
  appliedPrice: 7000, // Override price
});
```

---

## Notes

1. **Patient Name:** Auto-filled from client name if not provided
2. **Applied Price:** Immutable after creation (audit trail)
3. **Lab Service:** Optional but recommended for pricing integration
4. **Status:** Triggers invoice generation when set to "In Production"
5. **Activity Log:** All changes tracked for audit purposes

---

## Related Endpoints

- Lab Services: `/api/lab-services` (CRUD)
- Lab Prices: `/api/lab-service-prices` (CRUD)
- Lab Price For Date: `/api/lab-services/price-for-date` (GET active price)
