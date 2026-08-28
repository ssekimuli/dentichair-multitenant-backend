# Lab Prices Management Implementation Plan

## Overview
Add functionality to set and manage laboratory service prices for different labs per branch. This allows users to configure standard pricing for lab services and services to be charged at configured rates.

---

## Phase 1: Database Schema

### 1.1 Create Lab Service Items Table
**File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_lab_services_table.php`

Create a new `lab_services` table to store available laboratory services that can be priced:

```
Columns:
- id (primary key)
- service_name (string) - Name of the service (e.g., "Crown", "Bridge", "Denture")
- category (string, nullable) - Service category
- is_active (boolean, default: true)
- branch_id (foreign key)
- created_at, updated_at
- deleted_at (soft delete)

Relationships:
- belongsTo Branch
- hasMany LabServicePrices
```

### 1.2 Create Lab Service Prices Table
**File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_lab_service_prices_table.php`

Create a new `lab_service_prices` table to store pricing per lab/service:

```
Columns:
- id (primary key)
- lab_service_id (foreign key)
- lab (string) - Lab name for quick reference
- base_price (decimal 10,2) - Standard price for the service
- branch_id (foreign key)
- is_active (boolean, default: true)
- created_at, updated_at

Relationships:
- belongsTo LabService
- belongsTo Branch
```

### 1.3 Update Laboratory Orders Table
**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_lab_service_id_to_laboratory_orders_table.php`

Add reference to lab service for better pricing tracking:

```
Additions:
- lab_service_id (foreign key, nullable) - Link to configured lab service
- applied_price (decimal 10,2, nullable) - The price that was applied at order creation
```

---

## Phase 2: Models

### 2.1 Create LabService Model
**File:** `app/Models/LabService.php`

```php
Relationships:
- belongsTo(Branch)
- hasMany(LabServicePrice)
- hasMany(LaboratoryOrder) - through lab_service_id

Methods:
- getActivePrice() - Get currently active price
- getPrices() - Get all prices for this service
```

### 2.2 Create LabServicePrice Model
**File:** `app/Models/LabServicePrice.php`

```php
Relationships:
- belongsTo(LabService)
- belongsTo(Branch)

Methods:
- scopeActive() - Query scope for active prices
- scopeForBranch($branchId)
```

### 2.3 Update LaboratoryOrder Model
**File:** `app/Models/LaboratoryOrder.php`

Add relationships:
```php
- belongsTo(LabService) - optional, via lab_service_id
```

---

## Phase 2.5: Database Seeders

### 2.5.1 Create Lab Service Seeder
**File:** `database/seeders/LabServiceSeeder.php`

Seeds initial lab services for each branch:
```php
- Create common lab services (Crown, Bridge, Denture, Implant, etc.)
- Assign to appropriate branches
- Set is_active to true
```

### 2.5.2 Create Lab Service Price Seeder
**File:** `database/seeders/LabServicePriceSeeder.php`

Seeds initial prices for lab services:
```php
- Create base prices for common labs (Premier Lab, Standard Lab, etc.)
- Link to lab services from 2.5.1
- Set is_active to true
```

### 2.5.3 Update DatabaseSeeder
**File:** `database/seeders/DatabaseSeeder.php`

Call the new seeders in appropriate order:
```php
$this->call([
    // ... existing seeders
    LabServiceSeeder::class,
    LabServicePriceSeeder::class,
]);
```

---

## Phase 3: API Endpoints

### 3.1 Lab Services Controller
**File:** `app/Http/Controllers/Api/LabServiceController.php`

Endpoints:
- `GET /api/lab-services` - List all lab services for user's branch
- `POST /api/lab-services` - Create new lab service
- `PUT /api/lab-services/{id}` - Update lab service
- `DELETE /api/lab-services/{id}` - Delete lab service (soft delete)
- `GET /api/lab-services/{id}` - Get single lab service with prices

### 3.2 Lab Service Prices Controller
**File:** `app/Http/Controllers/Api/LabServicePriceController.php`

Endpoints:
- `GET /api/lab-services/{id}/prices` - Get all prices for a lab service
- `POST /api/lab-services/{id}/prices` - Add new price for service
- `PUT /api/lab-service-prices/{id}` - Update price
- `DELETE /api/lab-service-prices/{id}` - Delete price
- `GET /api/lab-services/price-for-date` - Get active price for service on specific date (query params: service_id, date)

### 3.3 Update Laboratory Orders Controller
**File:** `app/Http/Controllers/Api/LaboratoryOrderController.php` (if exists) or `app/Http/Controllers/LaboratoryOrderController.php`

Enhancement:
- When creating order, check for configured lab service and apply price automatically
- Option to manually override price

---

## Phase 4: Request Validation

### 4.1 Store Lab Service Request
**File:** `app/Http/Requests/StoreLabServiceRequest.php`

Rules:
- lab (required, string)
- service_name (required, string, max: 255)
- category (nullable, string)
- is_active (boolean)

### 4.2 Store Lab Service Price Request
**File:** `app/Http/Requests/StoreLabServicePriceRequest.php`

Rules:
- lab_service_id (required, exists: lab_services)
- base_price (required, numeric, min: 0)
- is_active (boolean)

---

## Phase 5: Resources/DTOs

### 5.1 Lab Service Resource
**File:** `app/Http/Resources/LabServiceResource.php`

Return:
```json
{
  "id": 1,
  "lab": "Premier Lab",
  "service_name": "Crown",
  "category": "Prosthodontics",
  "is_active": true,
  "current_price": null, // populated if price query param included
  "prices": [], // array of prices if prices endpoint
  "branch_id": 1,
  "created_at": "2026-08-28T...",
  "updated_at": "2026-08-28T..."
}
```

### 5.2 Lab Service Price Resource
**File:** `app/Http/Resources/LabServicePriceResource.php`

Return:
```json
{
  "id": 1,
  "lab_service_id": 1,
  "lab": "Premier Lab",
  "base_price": 5000,
  "is_active": true,
  "created_at": "2026-08-28T...",
  "updated_at": "2026-08-28T..."
}
```

---

## Phase 6: Routes

**File:** `routes/api.php`

```php
Route::middleware(['auth:sanctum', 'branch.context'])->group(function () {
    Route::resource('lab-services', LabServiceController::class);
    Route::get('lab-services/{labService}/prices', [LabServiceController::class, 'prices']);
    
    Route::resource('lab-service-prices', LabServicePriceController::class)->only(['store', 'update', 'destroy']);
    Route::get('lab-services/price-for-date', [LabServicePriceController::class, 'priceForDate']);
    
    // Update laboratory orders if needed
    Route::patch('laboratory-orders/{order}/price', [LaboratoryOrderController::class, 'updatePrice']);
});
```

---

## Phase 7: Frontend Integration Points (API Contract)

### 7.1 Lab Prices Management UI
- List all lab services
- Add/edit/delete lab services
- Manage pricing history for each service
- Set effective dates for prices
- View price trends

### 7.2 Lab Order Creation
- Auto-populate price when lab service is selected
- Allow manual override
- Show price history in dropdown

### 7.3 Reporting
- Lab service pricing report
- Price history audit trail
- Cost analysis by lab service

---

## Phase 8: Business Logic

### 8.1 Price Selection Logic
When creating/updating lab order:
1. Check if `lab_service_id` is provided
2. Query `LabServicePrice` for that service
3. Use active price if available
4. If no active price found, allow manual entry

### 8.2 Price Validation
- Only one active price per service at a time
- Price cannot be negative or zero

### 8.3 Audit Trail
- Track all price changes (by existing `LogsActivity` trait)
- Keep historical prices (soft delete or date-based approach)

---

## Phase 9: Permissions

Update permission system to include:
- `manage_lab_services` - Create/update/delete lab services
- `manage_lab_prices` - Set and modify prices
- `view_lab_prices` - View pricing information

---

## Phase 10: Testing Strategy

### 10.1 Unit Tests
- `LabService` model relationships
- `LabServicePrice` model scopes and methods
- Price selection logic for given dates

### 10.2 Feature Tests
- Create lab service endpoint
- Update price endpoint
- Get active price for date
- Lab order creation with auto-price
- Price validation (no overlaps, etc.)

### 10.3 Integration Tests
- Full lab order workflow with pricing
- Multiple branches isolation
- Historical price retrieval

---

## Implementation Order

1. **Step 1:** Create database migrations (Phase 1)
2. **Step 2:** Create models (Phase 2)
3. **Step 3:** Create controllers and routes (Phase 3 & 6)
4. **Step 4:** Create form requests (Phase 4)
5. **Step 5:** Create resources/DTOs (Phase 5)
6. **Step 6:** Update LaboratoryOrder logic (Phase 8)
7. **Step 7:** Add permissions (Phase 9)
8. **Step 8:** Write tests (Phase 10)
9. **Step 9:** Documentation

---

## Key Considerations

- **Multi-branch:** Each branch has independent lab services and pricing
- **Currency:** Respect branch currency settings
- **Soft deletes:** Use soft deletes for data preservation
- **Price history:** Keep all historical prices for audit
- **Effective dates:** Support price changes over time
- **Backup compatibility:** Orders store applied price for historical accuracy
- **Fallback:** Allow manual price entry if service not configured

---

## Future Enhancements

- Price adjustment percentages per service
- Bulk price import/export
- Automatic price calculations based on markup
- Lab-specific pricing tiers
- Integration with inventory costs
- Pricing analytics and trends
- Scheduled price changes
