# Lab Prices API - Search & Filter Documentation

## Overview
The Lab Prices API now includes comprehensive search and filtering capabilities across lab services and pricing data.

## Lab Services Search

### List Lab Services with Filters
**Endpoint:** `GET /api/lab-services`

**Query Parameters:**
- `search` (string, optional) - Search by service name or category
- `category` (string, optional) - Filter by category (e.g., "Prosthodontics", "Implantology", "Cosmetic")
- `active` (boolean, optional) - Filter by active status (true/false)

**Examples:**

#### Search by service name
```bash
GET /api/lab-services?search=crown
```

#### Filter by category
```bash
GET /api/lab-services?category=Prosthodontics
```

#### Get only active services
```bash
GET /api/lab-services?active=true
```

#### Combine multiple filters
```bash
GET /api/lab-services?search=implant&category=Implantology&active=true
```

**Response:**
```json
[
  {
    "id": 4,
    "service_name": "Implant",
    "category": "Implantology",
    "is_active": true,
    "current_price": 20000,
    "prices": [...],
    "branch_id": 1,
    "created_at": "2026-08-28T...",
    "updated_at": "2026-08-28T..."
  }
]
```

---

## Lab Service Prices Search

### List Lab Service Prices with Filters
**Endpoint:** `GET /api/lab-service-prices`

**Query Parameters:**
- `search` (string, optional) - Search by lab name or service name
- `lab` (string, optional) - Filter by lab name (exact match)
- `service_id` (integer, optional) - Filter by lab service ID
- `min_price` (decimal, optional) - Filter prices >= min_price
- `max_price` (decimal, optional) - Filter prices <= max_price
- `active` (boolean, optional) - Filter by active status (true/false)

**Examples:**

#### Search by lab name
```bash
GET /api/lab-service-prices?search=premier
```

#### Filter by specific lab
```bash
GET /api/lab-service-prices?lab=Premier%20Lab
```

#### Filter by price range
```bash
GET /api/lab-service-prices?min_price=3000&max_price=5000
```

#### Get prices for specific service
```bash
GET /api/lab-service-prices?service_id=1
```

#### Get active prices only
```bash
GET /api/lab-service-prices?active=true
```

#### Combine multiple filters
```bash
GET /api/lab-service-prices?lab=Standard%20Lab&min_price=2000&max_price=6000&active=true
```

#### Find all Crown prices from Premier Lab
```bash
GET /api/lab-service-prices?search=crown&lab=Premier%20Lab
```

**Response:**
```json
[
  {
    "id": 1,
    "lab_service_id": 1,
    "lab": "Premier Lab",
    "base_price": "5000.00",
    "is_active": true,
    "created_at": "2026-08-28T...",
    "updated_at": "2026-08-28T..."
  }
]
```

---

## Get Service Prices

### Get All Prices for a Specific Service
**Endpoint:** `GET /api/lab-services/{id}/prices`

**URL Parameters:**
- `id` (integer, required) - Lab service ID

**Example:**
```bash
GET /api/lab-services/1/prices
```

**Response:**
```json
[
  {
    "id": 1,
    "lab_service_id": 1,
    "lab": "Premier Lab",
    "base_price": "5000.00",
    "is_active": true,
    "created_at": "2026-08-28T...",
    "updated_at": "2026-08-28T..."
  },
  {
    "id": 2,
    "lab_service_id": 1,
    "lab": "Standard Lab",
    "base_price": "4000.00",
    "is_active": false,
    "created_at": "2026-08-28T...",
    "updated_at": "2026-08-28T..."
  }
]
```

---

## Get Active Price for Service

### Get Currently Active Price
**Endpoint:** `GET /api/lab-services/price-for-date`

**Query Parameters:**
- `service_id` (integer, required) - Lab service ID

**Example:**
```bash
GET /api/lab-services/price-for-date?service_id=1
```

**Response:**
```json
{
  "id": 1,
  "lab_service_id": 1,
  "lab": "Premier Lab",
  "base_price": "5000.00",
  "is_active": true,
  "created_at": "2026-08-28T...",
  "updated_at": "2026-08-28T..."
}
```

**Error Response (404):**
```json
{
  "error": "No active price found"
}
```

---

## Search Best Practices

### 1. Partial Search
Search queries use `LIKE` matching, so partial matches work:
```bash
GET /api/lab-services?search=den
# Returns: Denture, Implant (no match), etc.
```

### 2. Case Insensitive
All searches are case-insensitive:
```bash
GET /api/lab-services?search=CROWN
# Same as: ?search=crown
```

### 3. Price Range Filtering
Get services in a specific price range:
```bash
GET /api/lab-service-prices?min_price=1000&max_price=5000
```

### 4. Filter by Lab Tier
Find all services from a specific lab tier:
```bash
GET /api/lab-service-prices?lab=Economy%20Lab
```

### 5. Combine Filters
Build complex queries with multiple filters:
```bash
GET /api/lab-service-prices?search=crown&lab=Premier%20Lab&min_price=4000&active=true
```

---

## Query Parameters Reference

| Parameter | Type | Model | Notes |
|-----------|------|-------|-------|
| `search` | string | Both | Partial match, case-insensitive |
| `category` | string | LabService | Exact match |
| `active` | boolean | Both | true/false |
| `lab` | string | LabServicePrice | Exact match |
| `service_id` | integer | LabServicePrice | Filter by service ID |
| `min_price` | decimal | LabServicePrice | Greater than or equal |
| `max_price` | decimal | LabServicePrice | Less than or equal |

---

## Performance Tips

1. **Use specific filters** - More specific queries are faster
2. **Combine with pagination** - For large result sets, consider adding pagination
3. **Cache results** - Consider caching search results on the frontend
4. **Index searches** - The database has indexes on common search fields

---

## Error Handling

All endpoints return appropriate HTTP status codes:
- `200 OK` - Successful search/filter
- `404 Not Found` - No active price found
- `401 Unauthorized` - Missing or invalid authentication
- `422 Unprocessable Entity` - Invalid query parameters

---

## Examples in Different Languages

### JavaScript/Fetch
```javascript
// Search for crown services
const response = await fetch('/api/lab-services?search=crown', {
  headers: { 'Authorization': `Bearer ${token}` }
});
const services = await response.json();

// Get prices in range
const prices = await fetch('/api/lab-service-prices?min_price=2000&max_price=5000', {
  headers: { 'Authorization': `Bearer ${token}` }
}).then(r => r.json());
```

### PHP/cURL
```php
// Search for services
$url = 'https://api.example.com/api/lab-services?search=implant';
$headers = ['Authorization: Bearer ' . $token];
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true
]);
$result = json_decode(curl_exec($ch));
```

### Python/Requests
```python
import requests

# Search with filters
params = {
    'search': 'crown',
    'category': 'Prosthodontics',
    'active': True
}
headers = {'Authorization': f'Bearer {token}'}
response = requests.get(
    'https://api.example.com/api/lab-services',
    params=params,
    headers=headers
)
services = response.json()
```
