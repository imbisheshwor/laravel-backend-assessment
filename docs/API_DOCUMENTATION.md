# Hospital HMIS API — Documentation

A REST API for patient registration, service orders, and payments in a simplified Hospital Management Information System (HMIS).

- **Framework:** Laravel 12
- **Database:** PostgreSQL 17
- **Base URL:** `http://localhost:8000`
- **All requests must include:**

```
Accept: application/json
Content-Type: application/json
```

---

## Table of Contents

1. [General Conventions](#1-general-conventions)
2. [Patients](#2-patients)
3. [Departments](#3-departments)
4. [Services](#4-services)
5. [Orders](#5-orders)
6. [Payments](#6-payments)
7. [Error Reference](#7-error-reference)
8. [Concurrency & Idempotency](#8-concurrency--idempotency)
9. [Audit Log](#9-audit-log)
10. [Queue & Background Processing](#10-queue--background-processing)

---

## 1. General Conventions

### HTTP Status Codes

| Code | Meaning |
|------|---------|
| `200` | Success (read / update / replayed payment) |
| `201` | Created successfully |
| `404` | Resource not found |
| `409` | Conflict (e.g. already paid, key belongs to different order) |
| `422` | Validation error — see `errors` in response body |
| `500` | Server error (e.g. simulated rollback in dev) |

### Response Envelope

All successful responses wrap data in a `data` key:

```json
{
  "data": { ... }
}
```

Paginated responses follow Laravel's pagination structure:

```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": null, "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 20, "from": 1, "to": 20, "path": "..." }
}
```

### Validation Errors (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Error message here."]
  }
}
```

### Request Tracing

Every response includes an `X-Request-Id` header (UUID) that correlates to audit log entries.

---

## 2. Patients

### `POST /api/patients` — Register a Patient

**Request Body:**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `full_name` | string | ✅ | max 150 chars |
| `date_of_birth` | string | ✅ | Format: `YYYY-MM-DD`, must be today or past |
| `sex` | string | ✅ | One of: `female`, `male`, `other`, `unknown` |
| `phone` | string | ❌ | max 30 chars |

**Example Request:**
```json
{
  "full_name": "Ram Bahadur Thapa",
  "date_of_birth": "1985-07-21",
  "sex": "male",
  "phone": "+977-9841000001"
}
```

**Example Response `201`:**
```json
{
  "data": {
    "id": 1,
    "full_name": "Ram Bahadur Thapa",
    "date_of_birth": "1985-07-21",
    "sex": "male",
    "phone": "+977-9841000001",
    "created_at": "2026-10-26T05:16:00.000000Z",
    "updated_at": "2026-10-26T05:16:00.000000Z"
  }
}
```

---

### `GET /api/patients/{id}` — Get Patient

**Path Parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `id` | integer | Patient ID |

**Example Response `200`:**
```json
{
  "data": {
    "id": 1,
    "full_name": "Ram Bahadur Thapa",
    "date_of_birth": "1985-07-21",
    "sex": "male",
    "phone": "+977-9841000001",
    "created_at": "2026-10-26T05:16:00.000000Z",
    "updated_at": "2026-10-26T05:16:00.000000Z"
  }
}
```

**Error `404`:** Patient not found.

---

### `GET /api/patients/{id}/orders` — Patient Order History

Returns paginated orders for a specific patient. Supports all the same filter parameters as [Search Orders](#get-apiorders--search-orders).

**Query Parameters:**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `status` | string | — | `paid` or `unpaid` |
| `from` | string | — | Start date `YYYY-MM-DD` (inclusive) |
| `to` | string | — | End date `YYYY-MM-DD` (inclusive) |
| `service_code` | string | — | Filter by service code in order items |
| `department_code` | string | — | Filter by department code in order items |
| `per_page` | integer | `20` | 1–100 |
| `page` | integer | `1` | |

**Example:** `GET /api/patients/1/orders?status=paid&per_page=10`

---

## 3. Departments

### `POST /api/departments` — Create Department

**Request Body:**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `code` | string | ✅ | Unique, max 32, uppercase letters/digits/`_`/`-`, e.g. `PATHO` |
| `name` | string | ✅ | max 150 chars |
| `is_active` | boolean | ❌ | Defaults to `true` |

**Example Request:**
```json
{
  "code": "DENTAL",
  "name": "Dental Department"
}
```

**Example Response `201`:**
```json
{
  "data": {
    "id": 5,
    "code": "DENTAL",
    "name": "Dental Department",
    "is_active": true,
    "created_at": "2026-10-26T05:18:00.000000Z",
    "updated_at": "2026-10-26T05:18:00.000000Z"
  }
}
```

**Validation errors:**
- `code` must be unique — `422` if already exists.

---

### `GET /api/departments` — List Departments

**Query Parameters:**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `per_page` | integer | `20` | 1–100 |
| `page` | integer | `1` | |

**Example:** `GET /api/departments?per_page=10`

---

### `PATCH /api/departments/{id}` — Update Department

All fields are optional. Send only the fields you want to change.

**Request Body:**

| Field | Type | Rules |
|-------|------|-------|
| `code` | string | Unique (ignores self), max 32 |
| `name` | string | max 150 |
| `is_active` | boolean | |

**Example Request:**
```json
{
  "is_active": false
}
```

> [!WARNING]
> Deactivating a department prevents new services from being created under it and prevents new orders from including its services. Existing orders and their price snapshots are unaffected.

---

## 4. Services

### `POST /api/services` — Create Service

**Request Body:**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `service_code` | string | ✅ | Unique, max 32, uppercase + digits + `_` + `-` |
| `name` | string | ✅ | max 150 chars |
| `department_id` | integer | ✅ | Must exist and be **active** |
| `unit_price` | string | ✅ | Decimal string, e.g. `"500.00"`. Must be > 0, max `99999999.99` |
| `is_active` | boolean | ❌ | Defaults to `true` |

> [!IMPORTANT]
> `unit_price` must be sent as a **decimal string** (e.g. `"500.00"`), not a number. This avoids floating-point precision issues.

**Example Request:**
```json
{
  "service_code": "DENT-001",
  "name": "Dental Consultation",
  "department_id": 5,
  "unit_price": "750.00"
}
```

**Example Response `201`:**
```json
{
  "data": {
    "id": 5,
    "service_code": "DENT-001",
    "name": "Dental Consultation",
    "department_id": 5,
    "unit_price": "750.00",
    "is_active": true,
    "created_at": "2026-10-26T05:20:00.000000Z",
    "updated_at": "2026-10-26T05:20:00.000000Z"
  }
}
```

---

### `GET /api/services` — List / Filter Services

**Query Parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `department_code` | string | Filter by department code, e.g. `PATHO` |
| `is_active` | boolean | `1` / `0` or `true` / `false` |
| `per_page` | integer | 1–100, default `20` |
| `page` | integer | default `1` |

**Examples:**

```
GET /api/services?department_code=PATHO&is_active=1
GET /api/services?is_active=0
```

Response includes nested `department` relation.

---

### `PATCH /api/services/{id}` — Update Service

All fields are optional. Send only what you want to update.

**Request Body:**

| Field | Type | Rules |
|-------|------|-------|
| `service_code` | string | Unique (ignores self) |
| `name` | string | max 150 |
| `department_id` | integer | Must be active |
| `unit_price` | string | Decimal string, > 0 |
| `is_active` | boolean | |

**Example — update price only:**
```json
{
  "unit_price": "550.25"
}
```

**Example — deactivate:**
```json
{
  "is_active": false
}
```

> [!NOTE]
> Updating `unit_price` does **not** change the price on any existing orders. Each order item stores a snapshot of the price at the time of order creation.

---

## 5. Orders

### `POST /api/orders` — Create Order

Creates an order with one or more service items. The server calculates the total — client-supplied totals or unit prices are **rejected**.

**Request Body:**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `patient_id` | integer | ✅ | Must exist |
| `items` | array | ✅ | 1–100 items |
| `items[].service_code` | string | ✅ | Must exist, service and department must be active, no duplicates |
| `items[].quantity` | integer | ✅ | JSON integer only, 1–10 |

> [!CAUTION]
> Do NOT send `unit_price` or `total` in the request body — they will cause a 422 validation error. The server looks up prices from the service master.

**Example Request:**
```json
{
  "patient_id": 1,
  "items": [
    {"service_code": "LAB-001", "quantity": 2},
    {"service_code": "RAD-001", "quantity": 1}
  ]
}
```

**Example Response `201`:**
```json
{
  "data": {
    "id": 42,
    "patient_id": 1,
    "status": "unpaid",
    "total": "2200.00",
    "paid_at": null,
    "created_at": "2026-10-26T05:25:00.000000Z",
    "updated_at": "2026-10-26T05:25:00.000000Z",
    "items": [
      {
        "id": 83,
        "order_id": 42,
        "service_id": 1,
        "department_id": 1,
        "service_code": "LAB-001",
        "service_name": "Complete Blood Count (CBC)",
        "unit_price": "500.00",
        "quantity": 2,
        "line_total": "1000.00",
        "created_at": "2026-10-26T05:25:00.000000Z",
        "updated_at": "2026-10-26T05:25:00.000000Z"
      },
      {
        "id": 84,
        "order_id": 42,
        "service_id": 2,
        "department_id": 2,
        "service_code": "RAD-001",
        "service_name": "X-Ray Chest",
        "unit_price": "1200.00",
        "quantity": 1,
        "line_total": "1200.00",
        "created_at": "2026-10-26T05:25:00.000000Z",
        "updated_at": "2026-10-26T05:25:00.000000Z"
      }
    ]
  }
}
```

**Validation failures (422):**
- Unknown service code
- Inactive service or inactive department
- Duplicate service code in items
- Quantity < 1 or > 10, or not a JSON integer (floats/strings rejected)
- Client-supplied `unit_price` or `total`

> All items are validated before any database write. A single invalid item rolls back the entire order.

---

### `GET /api/orders` — Search Orders

Search and filter orders across the full dataset with pagination.

**Query Parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `status` | string | `paid` or `unpaid` |
| `patient_id` | integer | Filter by patient |
| `from` | string | Start date `YYYY-MM-DD` (inclusive) |
| `to` | string | End date `YYYY-MM-DD` (inclusive). Must be ≥ `from` |
| `service_code` | string | Orders that contain this service code (historical snapshot) |
| `department_code` | string | Orders that contain an item from this department |
| `per_page` | integer | 1–100, default `20` |
| `page` | integer | 1–100000 |

> [!TIP]
> `service_code` and `department_code` filters query the **snapshotted** values in `order_items` — not the current service master. This means you can find orders created when a service was active, even after it was renamed or moved.

**Examples:**

```
GET /api/orders?status=paid&from=2026-01-01&to=2026-01-31
GET /api/orders?service_code=LAB-001&department_code=PATHO
GET /api/orders?patient_id=5&per_page=50
```

Results are sorted by `created_at DESC`, `id DESC`. Uses `simplePaginate` (no full COUNT on large datasets).

---

## 6. Payments

### `POST /api/orders/{id}/pay` — Pay an Order

Processes payment for an order. This is the most concurrency-sensitive endpoint.

**Path Parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `id` | integer | Order ID |

**Required Header:**

| Header | Description |
|--------|-------------|
| `Idempotency-Key` | Required. 1–128 chars, letters/digits/`.`/`_`/`:`/`-`. Globally unique across all payments. |

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `simulate_failure` | boolean | ❌ | `true` to test rollback. Only works if `PAYMENT_FAILURE_SIMULATION=true` in `.env` and app is in `local`/`testing` env. |

> [!CAUTION]
> Do NOT send `amount` in the request body. The payment amount is always taken from the order's stored total. Sending `amount` causes a 422.

**Example Request:**
```http
POST /api/orders/42/pay
Content-Type: application/json
Accept: application/json
Idempotency-Key: pay-order-42-attempt-1

{}
```

**Example Response `201` (new payment):**
```json
{
  "data": {
    "id": 10,
    "order_id": 42,
    "idempotency_key": "pay-order-42-attempt-1",
    "amount": "2200.00",
    "status": "succeeded",
    "created_at": "2026-10-26T05:30:00.000000Z",
    "updated_at": "2026-10-26T05:30:00.000000Z"
  },
  "replayed": false
}
```

**Example Response `200` (idempotent replay — same key retried):**
```json
{
  "data": { ... same payment record ... },
  "replayed": true
}
```

**Example Response `409` (already paid with different key):**
```json
{
  "message": "Order is already paid with a different idempotency key."
}
```

**Example Response `409` (key used for another order):**
```json
{
  "message": "Idempotency key belongs to a different order."
}
```

### Idempotency Behaviour Summary

| Scenario | Response |
|----------|----------|
| New unpaid order, new key | `201` — payment created |
| Same key retried (any timing) | `200` — original payment returned, `replayed: true` |
| Paid order, different key | `409` — conflict |
| Key already used on a different order | `409` — conflict |
| 20 concurrent requests, same key | 1× `201`, 19× `200` — exactly one payment |
| 20 concurrent requests, different keys | 1× `201`, 19× `409` — exactly one payment |

---

### Rollback Simulation (Dev Only)

To test that a failed payment rolls back cleanly:

1. Set `PAYMENT_FAILURE_SIMULATION=true` in `.env` (local/testing only)
2. Pay with `"simulate_failure": true`
3. The server inserts the payment record, then throws an exception — the whole transaction rolls back
4. The order stays `unpaid`, no payment exists, no outbox row
5. Retry with a new (or same) key and no `simulate_failure` → succeeds normally

```bash
curl -X POST http://localhost:8000/api/orders/1/pay \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: test-rollback-001' \
  -d '{"simulate_failure": true}'
# Returns 500 — order is still unpaid

curl -X POST http://localhost:8000/api/orders/1/pay \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: test-rollback-001' \
  -d '{}'
# Returns 201 — payment now succeeds
```

---

## 7. Error Reference

### 422 — Validation Error Examples

**Missing required fields:**
```json
{
  "message": "The full name field is required.",
  "errors": {
    "full_name": ["The full name field is required."],
    "date_of_birth": ["The date of birth field is required."]
  }
}
```

**Inactive service in order:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "items.0.service_code": ["Service must exist and both service and department must be active."]
  }
}
```

**Duplicate service codes:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "items.0.service_code": ["The items.0.service_code field has a duplicate value."],
    "items.1.service_code": ["The items.1.service_code field has a duplicate value."]
  }
}
```

**Client-supplied price:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "items.0.unit_price": ["The items.0.unit_price field is not allowed."]
  }
}
```

**Missing Idempotency-Key:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "idempotency_key": ["The idempotency key field is required."]
  }
}
```

---

## 8. Concurrency & Idempotency

### How concurrent payments are prevented

The payment flow uses two PostgreSQL-level locks inside a single transaction:

1. **Advisory lock on the idempotency key** (`pg_advisory_xact_lock`) — serializes all payment attempts sharing the same key, including across different orders.
2. **Row-level `FOR UPDATE` lock on the order** — serializes payment attempts using *different* keys for the same order.

Backed by **unique database constraints** on `payments.order_id` and `payments.idempotency_key` as a final safeguard.

### How price snapshots are protected during order creation

`CreateOrder` reads service and department rows with `FOR SHARE OF s, d`. This blocks any concurrent `PATCH /api/services/{id}` price updates until the order and its items are committed. The snapshot is therefore always consistent with one point in time.

### Testing concurrency

Run 20 real parallel processes against the same order:

```bash
# Same idempotency key
php scripts/concurrent-payments.php --order=ORDER_ID --workers=20 --mode=same

# Different idempotency keys
php scripts/concurrent-payments.php --order=ORDER_ID --workers=20 --mode=different
```

---

## 9. Audit Log

The following events are automatically recorded in `audit_logs`:

| Event | Trigger |
|-------|---------|
| `order.created` | Order successfully created |
| `order.status_changed` | Order status changes (unpaid → paid) |
| `payment.attempted` | Every call to the pay endpoint (survives rollback) |
| `payment.succeeded` | Payment committed |
| `payment.failed` | Transaction rolled back |
| `payment.replayed` | Idempotent replay returned |

Each audit entry contains:
- `event` — what happened
- `entity_type` / `entity_id` — which record
- `old_values` / `new_values` — JSON before/after
- `request_id` — UUID correlating to `X-Request-Id` header
- `actor` — `"anonymous"` (no auth in this API)
- `ip_address` — client IP
- `created_at` — timestamp

> [!IMPORTANT]
> Audit logs are **append-only**. A PostgreSQL trigger prevents any `UPDATE`, `DELETE`, or `TRUNCATE` on the `audit_logs` table — even direct SQL. Only a database administrator can modify the schema.

---

## 10. Queue & Background Processing

After a payment commits, a background job `ProcessOrderDepartments` is dispatched. It creates one `department_order_processings` record per department involved in the order.

For example, an order with `LAB-001` (PATHO) and `RAD-001` (RADIO) will produce:
- 1 payment
- 2 department processing records (PATHO + RADIO)

### Running the queue worker

```bash
php artisan queue:work --sleep=1 --tries=5 --timeout=60
```

### Transactional Outbox

A `processing_outbox` row is written inside the payment transaction. If the application crashes after the transaction commits but before the job is dispatched, the outbox publisher recovers it:

```bash
php artisan outbox:publish         # manually publish pending rows
php artisan schedule:work          # run the scheduler (auto-runs outbox:publish)
```

The publisher is scheduled and handles up to 1,000 pending rows per run (`--limit` to change). Jobs are idempotent — retrying a job never creates duplicate department handoffs.

---

## Seed Data

The default seeder inserts these services (safe to re-run — uses `insertOrIgnore`):

| Department Code | Department | Service Code | Service | Price |
|---|---|---|---|---:|
| PATHO | Pathology | LAB-001 | Complete Blood Count (CBC) | 500.00 |
| RADIO | Radiology | RAD-001 | X-Ray Chest | 1200.00 |
| CONSULT | Consultation | CON-001 | Doctor Consultation | 800.00 |
| CARDIO | Cardiology | ECG-001 | ECG | 600.00 |

```bash
php artisan migrate --seed     # fresh setup
php artisan db:seed            # re-seed without migrating
```

---

## Quick Start cURL Examples

```bash
# 1. Register a patient
curl -s -X POST http://localhost:8000/api/patients \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"full_name":"Sita Sharma","date_of_birth":"1992-03-10","sex":"female"}'

# 2. Create an order (use patient id from step 1)
curl -s -X POST http://localhost:8000/api/orders \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"patient_id":1,"items":[{"service_code":"LAB-001","quantity":1},{"service_code":"RAD-001","quantity":1}]}'

# 3. Pay the order (use order id from step 2)
curl -s -X POST http://localhost:8000/api/orders/1/pay \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: first-payment-001' \
  -d '{}'

# 4. Retry same payment (idempotent — returns same result)
curl -s -X POST http://localhost:8000/api/orders/1/pay \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: first-payment-001' \
  -d '{}'

# 5. Search paid orders in January 2026
curl -s 'http://localhost:8000/api/orders?status=paid&from=2026-01-01&to=2026-01-31' \
  -H 'Accept: application/json'

# 6. List active PATHO services
curl -s 'http://localhost:8000/api/services?department_code=PATHO&is_active=1' \
  -H 'Accept: application/json'

# 7. Update a service price
curl -s -X PATCH http://localhost:8000/api/services/1 \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"unit_price":"550.25"}'
```
