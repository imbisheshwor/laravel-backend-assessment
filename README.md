# Hospital Service API

A Laravel API for patient registration, service orders and payments. Departments, service codes and prices are managed in PostgreSQL. Paid orders are handed off to their departments through a database queue and transactional outbox.

## Setup

Requirements: PHP 8.2+, PostgreSQL 17, Composer 2, and PHP extensions `pdo_pgsql`, `mbstring`, `dom`, `xml`, `xmlreader` and `xmlwriter`. The native setup scripts also use Python 3 and curl. No frontend or Redis is needed.

### Native

```bash
bash scripts/setup-native.sh
php artisan serve --host=127.0.0.1 --port=8000
```

Setup copies `.env.example` when `.env` is missing, installs dependencies from `composer.lock`, creates the application key, runs migrations and seeds the service master. An existing application key and master edits are preserved.

The local PostgreSQL cluster is stored in `.runtime/postgresql-data/` and listens on `127.0.0.1:55432`. It contains `hmis` for application data and `hmis_test` for tests. It runs separately from system PostgreSQL. The credentials in `.env.example` are local development defaults.

```bash
bash scripts/local-postgres.sh start
bash scripts/local-postgres.sh status
bash scripts/local-postgres.sh stop
```

Stopping the cluster keeps its data. Set `PG_BINDIR` if PostgreSQL 17 is installed outside `/usr/lib/postgresql/17/bin`. To create a second cluster on another port, run `HMIS_NATIVE_PORT=55434 bash scripts/setup-native.sh` in that project's directory.

`scripts/composer.sh` uses system Composer or downloads a checksum-verified Composer PHAR when needed.

For an existing PostgreSQL installation, create the `hmis` and `hmis_test` databases, copy `.env.example`, set the database credentials, then run:

```bash
composer install
php artisan key:generate
php artisan migrate --seed
```

Run the worker and scheduler in separate terminals:

```bash
php artisan queue:work --sleep=1 --tries=5 --timeout=60
php artisan schedule:work
```

For a supervised deployment, run `php artisan schedule:run` every minute and keep the queue worker running. The database queue's retry interval is 120 seconds, longer than the worker timeout.

### Docker

```bash
bash scripts/setup.sh
```

This starts PostgreSQL, the API, a worker and the scheduler. The API is at `http://localhost:8000`; PostgreSQL is exposed on localhost port 55433. Set `HMIS_PG_PORT` to change the database host port. Containers connect to PostgreSQL on port 5432.

The database initialization script creates `hmis_test` when the volume is first created. For an older volume without that database:

```bash
docker compose exec db createdb -U hmis -O hmis hmis_test
```

`docker compose down` stops the services and retains the database volume.

## Data model

| Table | Main fields and constraints |
| --- | --- |
| `departments` | Unique code, name, active flag, timestamps |
| `services` | Unique service code, department FK, decimal unit price, active flag |
| `patients` | Full name, date of birth, sex, optional phone |
| `orders` | Patient FK, unpaid/paid status, decimal total, paid timestamp |
| `order_items` | Service FK, department/code/name/price snapshots, quantity, line total; unique order/service |
| `payments` | Unique order FK and globally unique idempotency key, amount, status |
| `audit_logs` | Event, entity, old/new JSON, request UUID, actor, IP and timestamp |
| `processing_outbox` | Unique order FK and processing acknowledgement |
| `department_order_processings` | Unique order/department pair, handoff status and timestamps |
| `jobs`, `failed_jobs` | Laravel database queue storage |

The seeder inserts these examples without overwriting existing values:

| Department | Service code | Service | Price |
| --- | --- | --- | ---: |
| PATHO | LAB-001 | Complete Blood Count (CBC) | 500.00 |
| RADIO | RAD-001 | X-Ray Chest | 1200.00 |
| CONSULT | CON-001 | Doctor Consultation | 800.00 |
| CARDIO | ECG-001 | ECG | 600.00 |

Codes use uppercase letters, digits, underscores and hyphens. Services require an active department and a price above zero, up to 99,999,999.99. Prices are sent as decimal strings with at most two fractional digits, such as `"500.00"`.

Services and departments can be deactivated. There are no deletion endpoints, and foreign keys preserve historical references. A service can still be deactivated after its department becomes inactive.

## API

Send `Accept: application/json` and `Content-Type: application/json`. Creates return 201, reads and updates return 200, validation errors return 422, missing records return 404, and payment conflicts return 409. Each response includes `X-Request-Id` for audit correlation.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/api/patients` | Register a patient |
| GET | `/api/patients/{id}` | Patient details |
| GET | `/api/patients/{id}/orders` | Paginated patient orders |
| POST / GET | `/api/departments` | Create or list departments |
| PATCH | `/api/departments/{id}` | Edit or deactivate a department |
| POST / GET | `/api/services` | Create or list services |
| PATCH | `/api/services/{id}` | Edit price, details or active status |
| POST / GET | `/api/orders` | Create or search orders |
| POST | `/api/orders/{id}/pay` | Pay an order |

[docs/api.http](docs/api.http) contains requests for all endpoints. Some examples:

```bash
curl http://localhost:8000/api/patients \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"full_name":"Example Patient","date_of_birth":"1990-01-01","sex":"unknown"}'

curl http://localhost:8000/api/services \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"service_code":"LAB-002","name":"Blood Glucose","department_id":1,"unit_price":"250.00"}'

curl http://localhost:8000/api/departments \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"code":"DENTAL","name":"Dental"}'

curl http://localhost:8000/api/services \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"service_code":"DENT-001","name":"Dental Consultation","department_id":5,"unit_price":"750.00"}'

curl -X PATCH http://localhost:8000/api/services/1 \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"unit_price":"550.25"}'

curl http://localhost:8000/api/orders \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"patient_id":1,"items":[{"service_code":"LAB-001","quantity":1},{"service_code":"RAD-001","quantity":1}]}'

curl http://localhost:8000/api/orders/1/pay \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: order-1-payment' -d '{}'

curl -H 'Accept: application/json' \
  'http://localhost:8000/api/services?department_code=PATHO&is_active=1'

curl -H 'Accept: application/json' \
  'http://localhost:8000/api/orders?status=paid&from=2026-01-01&to=2026-01-31&service_code=LAB-001&department_code=PATHO&per_page=20'
```

Use the IDs returned by create responses or list endpoints; the IDs above assume a fresh database.

## Orders and price snapshots

An order accepts 1–100 distinct service codes, with an integer quantity of 1–10 per item. Every service and its department must be active. Unknown, inactive or duplicate codes are rejected with field-level errors. Client totals and unit prices are rejected, including null values. An invalid item prevents the whole order from being created.

`CreateOrder` reads services joined to departments using `FOR SHARE OF s, d`, validates every item, then inserts the order, items and audit records in one transaction. The shared row locks block concurrent price and activation changes until the snapshot is saved. A price edit that commits before the locking read is included; an edit that comes later waits for the order transaction. Locks are ordered by service ID, and deadlocked transactions are retried up to five times.

Prices are converted from decimal strings to integer cents for multiplication and addition. Stored amounts use PostgreSQL numeric columns. No floating-point money calculations are used.

Price changes do not reprice existing orders, including unpaid ones. Items also keep the service code, name and department from creation. Moving a service later does not change where an existing order is routed. Payment uses the stored order total.

## Payments and idempotency

`Idempotency-Key` is required. It is case-sensitive and globally unique: 1–128 letters, digits, periods, underscores, colons or hyphens.

`PayOrder` takes a transaction-scoped advisory lock for the key, then locks the order with `FOR UPDATE`. The key lock handles concurrent reuse across orders; the order lock serializes payments using different keys. Unique constraints on both payment order ID and idempotency key provide a database safeguard.

| Request | Result |
| --- | --- |
| Unpaid order with a new key | 201, one new payment |
| Same order and key retried | 200, original payment, `replayed: true` |
| Paid order with another key | 409 |
| Key already used for another order | 409 |

Payment insertion, paid status, success/status audits and the outbox row commit together. A failed transaction rolls them all back and does not consume the key. Attempt and failure audits are recorded separately so they survive a business rollback. The endpoint simulates full payment in one currency; there is no external gateway.

### Rollback test

Set `PAYMENT_FAILURE_SIMULATION=true` in a local or testing environment, then pay a fresh unpaid order with:

```json
{"simulate_failure": true}
```

The endpoint throws after inserting the payment and returns 500. The order remains unpaid, with no payment, outbox row or department work. Retrying with `{}` and the same key succeeds. The switch is rejected outside local/testing environments or when the flag is disabled.

## Audit and department processing

Audit records include order creation, payment attempts/results and status changes, with old/new values, record identity, timestamp, request UUID and IP. There is no authentication flow, so the actor is recorded as `anonymous`. Database triggers reject audit updates, deletes and truncation. A database administrator can still change the schema.

After payment commits, `ProcessOrderDepartments` groups the snapshotted department IDs and creates one handoff per department. A CBC/X-Ray order therefore has one payment and two department handoffs. The unique `(order_id, department_id)` constraint prevents duplicate work.

The job locks the outbox row and commits the handoffs and acknowledgement together. Retries are safe. If the application crashes after payment commits but before dispatch, the outbox remains pending. The scheduled publisher sends pending work again until a worker acknowledges it. Delivery is at least once, so the handler deduplicates repeated jobs.

```bash
php artisan outbox:publish
php artisan queue:failed
php artisan queue:retry all
```

The publisher handles up to 1,000 rows per run. Its limit can be increased with `--limit`.

## Search and indexes

Order filters are `status`, `patient_id`, inclusive UTC `from`/`to` dates, historical `service_code` and `department_code`. Both item filters must match the same item. The service code is the saved snapshot; the department filter resolves its current master code to the saved department ID.

Results sort by creation time and ID descending. `per_page` is 1–100, default 20. `simplePaginate` avoids a full count on every request; follow `next_page_url` for the next page. Deep offset pagination remains a trade-off and could be replaced by cursor pagination.

Item filters use `EXISTS`, so several matching items cannot duplicate an order. Date predicates use timestamp ranges rather than functions on indexed columns.

| Index | Purpose |
| --- | --- |
| `orders(status, created_at DESC, id DESC)` | Status/date search in result order |
| `orders(created_at DESC, id DESC)` | Default and date-only listing |
| `orders(patient_id, created_at DESC, id DESC)` | Patient order history |
| `order_items(service_code, order_id)` | Service existence probes |
| `order_items(department_id, order_id)` | Department existence probes |
| Unique `order_items(order_id, service_id)` | Duplicate protection and item loading |
| `order_items(service_id)` | Service references |
| `services(department_id, is_active, id)` | Filtered service listing |
| `audit_logs(entity_type, entity_id, id)` | Record history |
| Partial pending-outbox index | Unacknowledged work |

Unique payment and department-processing keys also support their lookup and retry paths.

## Tests

```bash
bash scripts/verify.sh native
# Docker:
bash scripts/verify.sh docker
# Individual checks:
php artisan test
bash scripts/composer.sh analyse
```

Tests use PostgreSQL and rebuild the separate `hmis_test` database. They cover service management, validation, historical prices, concurrent price changes, atomic creation/payment, rollback, idempotency, audits, queue delivery/retry, outbox recovery and search. Do not run tests in parallel against the same test database.

The payment race tests start 20 independent PHP processes and database connections. Each waits at a shared barrier before entering Laravel's HTTP kernel. The same-key case produces one 201 and nineteen 200 responses. Different keys produce one 201 and nineteen 409 responses. Both cases leave one payment.

To run a race separately, create a fresh unpaid order for each command:

```bash
php scripts/concurrent-payments.php --order=ORDER_ID --workers=20 --mode=same
php scripts/concurrent-payments.php --order=ANOTHER_ORDER_ID --workers=20 --mode=different
```

## Large dataset

```bash
php artisan dataset:generate --patients=500000 --batch=2000
php artisan dataset:verify --require-large
php artisan orders:explain --require-large
# Run the sequence together:
bash scripts/benchmark.sh native
```

The generator uses `generate_series`, `INSERT ... SELECT` and temporary batch ID tables. Each default batch contains 2,000 patients, 8,000 orders and 32,000 items, plus supporting records. The temporary tables are analyzed before joins. PHP holds only the selected service IDs and aggregate results.

Four active master services are selected dynamically. Each batch locks their prices and departments, and half the orders are loaded as paid with matching payments, audits and handoffs. Batches commit independently; re-running the command appends a new dataset rather than resuming a partial run. A session advisory lock prevents two generators from running together.

The recorded run used PostgreSQL 17.11:

| Table | Rows |
| --- | ---: |
| Patients | 500,000 |
| Orders | 2,000,000 |
| Order items | 8,000,000 |
| Payments | 1,000,000 |
| Audit logs | 5,000,000 |
| Processing outbox | 1,000,000 |
| Department handoffs | 4,000,000 |

That is 10.5 million core rows and 21.5 million generated rows. Generation took 342.410 seconds with 53.125 MiB peak PHP RSS. The integrity command found no missing items, wrong totals, inconsistent payments/outbox state, missing audits or incorrect handoffs. It reads a consistent snapshot and saves aggregate counts in [docs/benchmarks/dataset-integrity.json](docs/benchmarks/dataset-integrity.json). Runtime logs are kept under ignored `.runtime/logs/`; database dumps are not included.

### Query plan

`orders:explain` runs the following after generation and saves the full output in [docs/benchmarks/orders-query-plan.txt](docs/benchmarks/orders-query-plan.txt):

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT o.* FROM orders o
WHERE o.status = 'paid'
  AND o.created_at >= '2026-01-01' AND o.created_at < '2026-02-01'
  AND EXISTS (
    SELECT 1 FROM order_items oi
    WHERE oi.order_id = o.id AND oi.service_code = 'LAB-001'
  )
ORDER BY o.created_at DESC, o.id DESC
LIMIT 21;
```

<!-- benchmark:start -->
Dataset: 10,500,000 core rows. Planning: 0.502 ms. Execution: 4.777 ms. Indexes: `orders_status_created_idx`, `items_service_code_order_idx`. No sort was needed.

```text
Limit  (cost=0.86..77.56 rows=21 width=50) (actual time=0.663..4.760 rows=21 loops=1)
  Buffers: shared hit=64 read=42
  ->  Nested Loop Semi Join  (cost=0.86..203472.81 rows=55707 width=50) (actual time=0.662..4.756 rows=21 loops=1)
        Buffers: shared hit=64 read=42
        ->  Index Scan using orders_status_created_idx on orders o  (cost=0.43..76366.78 rows=173868 width=50) (actual time=0.020..0.071 rows=21 loops=1)
              Index Cond: (((status)::text = 'paid'::text) AND (created_at >= '2026-01-01 00:00:00'::timestamp without time zone) AND (created_at < '2026-02-01 00:00:00'::timestamp without time zone))
              Buffers: shared hit=24
        ->  Index Only Scan using items_service_code_order_idx on order_items oi  (cost=0.43..1.20 rows=3 width=8) (actual time=0.222..0.222 rows=1 loops=21)
              Index Cond: ((service_code = 'LAB-001'::text) AND (order_id = o.id))
              Heap Fetches: 0
              Buffers: shared hit=40 read=42
Planning:
  Buffers: shared hit=97
Planning Time: 0.502 ms
Execution Time: 4.777 ms
```
<!-- benchmark:end -->

The ordered scan stops after 21 matching orders. The covering item index answers the service checks without heap fetches. SQL execution times vary with host load and cache state.

## Structure and scope

Controllers handle HTTP responses, Form Requests validate input, and three actions own transaction boundaries: `CreateOrder`, `SaveService` and `PayOrder`. Eloquent handles relationships and decimal serialization; set-based loading and reporting use SQL. This keeps locking and rollback behavior together without adding a repository layer.

The domain is limited to ordering and simulated full payment. It has no insurance, discounts, partial payments, refunds, authentication or clinical workflows. Historical orders are not repriced automatically. PostgreSQL-specific locking, checks and triggers are intentional.
