# Event-Driven Notification System

Case study implementation for Insider One.

Core scope completed so far:
- Notification Management API (single + batch create, status lookup, list/filter, cancel)
- Async queue processing with priority queues (`high`, `normal`, `low`)
- Idempotency support (single header and batch item key)
- Docker runtime with API + worker + Redis

## Architecture (Current)

- API: Laravel 13
- Database: SQLite
- Queue backend: Redis
- Worker: Laravel queue worker consuming `high,normal,low`

## Run With Docker (Recommended for Review)

Prerequisites:
- Docker Engine
- Docker Compose plugin (`docker compose`)

Start everything:

```bash
docker compose up --build
```

What starts:
- `app` on `http://localhost:8000`
- `worker` for async processing
- `redis` as queue backend

Health check:

```bash
curl -i http://localhost:8000/health
```

Stop:

```bash
docker compose down
```

## Local Run (Without Docker)

Prerequisites:
- PHP 8.3+
- Composer
- SQLite
- Redis (for real queue processing)

Setup:

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Run API:

```bash
php artisan serve
```

Run worker:

```bash
php artisan queue:work redis --queue=high,normal,low
```

## Example API Requests

Create single notification:

```bash
curl -X POST http://localhost:8000/api/notifications \
	-H "Content-Type: application/json" \
	-H "Idempotency-Key: single-001" \
	-d '{
		"channel":"sms",
		"recipient":"+905551234567",
		"content":"Flash sale starts now",
		"priority":"high"
	}'
```

Create batch:

```bash
curl -X POST http://localhost:8000/api/notifications \
	-H "Content-Type: application/json" \
	-d '{
		"notifications": [
			{"channel":"sms","recipient":"+905551234567","content":"A","priority":"high","idempotency_key":"batch-1"},
			{"channel":"email","recipient":"user@example.com","content":"B","priority":"normal","idempotency_key":"batch-2"}
		]
	}'
```

Fetch by ID:

```bash
curl http://localhost:8000/api/notifications/{id}
```

Fetch by batch ID:

```bash
curl http://localhost:8000/api/batches/{batchId}
```

List with filters:

```bash
curl "http://localhost:8000/api/notifications?status=queued&channel=sms&from=2026-06-01&to=2026-06-30&per_page=20"
```

Cancel pending/queued notification:

```bash
curl -X POST http://localhost:8000/api/notifications/{id}/cancel
```

## Test Suite

Run all tests:

```bash
php artisan test
```

Current baseline:
- full suite passing
- includes API behavior, validation, idempotency, queue dispatch, and job state transition coverage
