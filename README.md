# Event-Driven Notification System

Case study implementation for Insider One.

Core scope completed so far:
- Notification Management API (single + batch create, status lookup, list/filter, cancel)
- Async queue processing with priority queues (`high`, `normal`, `low`)
- Idempotency support (single header and batch item key)
- Docker runtime with API + worker + Redis
- Channel rate limiting (100 notifications/second/channel with delayed re-queue)

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

Metrics snapshot:

```bash
curl -sS http://localhost:8000/metrics
```

Stop:

```bash
docker compose down
```

## Set Up The Actual Provider (webhook.site)

1. Open https://webhook.site and copy your generated unique URL.
2. Configure webhook.site response so API gets task-compliant payload:

- Open your webhook page and go to Edit / Customize Response.
- Set status code to `202`.
- Set header `Content-Type: application/json`.
- Set response body to:

```json
{
	"messageId": "provider-demo-id",
	"status": "accepted",
	"timestamp": "{{timestamp}}"
}
```

Without this, webhook.site may return HTML and the worker will treat it as transient failure and retry.
3. Put your URL in `.env`:

```bash
NOTIFICATION_PROVIDER_WEBHOOK_URL=https://webhook.site/<your-uuid>
NOTIFICATION_PROVIDER_TIMEOUT_SECONDS=10
NOTIFICATION_RATE_LIMIT_PER_SECOND=100
NOTIFICATION_RATE_LIMIT_RELEASE_SECONDS=1
```

4. If you run with Docker, restart so containers receive updated env values:

```bash
docker compose down
docker compose up --build
```

5. Send a test notification:

```bash
curl -X POST http://localhost:8000/api/notifications \
	-H "Content-Type: application/json" \
	-d '{
		"channel":"sms",
		"recipient":"+905551234567",
		"content":"Provider integration test",
		"priority":"high"
	}'
```

6. Verify in webhook.site UI:
- You should see a POST request with payload fields: `to`, `channel`, `content`
- If worker is running, notification status should move to `sent`

7. Check the saved delivery result from API:

```bash
curl http://localhost:8000/api/notifications/<notification-id>
```

Look for:
- `status: sent`
- `provider_response.messageId`
- `provider_response.timestamp`

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

Provider setup (Step 4):

```bash
# set this to your webhook.site URL
NOTIFICATION_PROVIDER_WEBHOOK_URL=https://webhook.site/<your-uuid>
```

Run API:

```bash
php artisan serve
```

Run worker:

```bash
php artisan queue:work redis --queue=high,normal,low
```

## Channel Rate Limiting (Step 5)

- Limit is enforced per `channel` (sms/email/push)
- Default is `100` messages per second per channel
- When limit is exceeded, job is re-queued with a short delay (`1` second by default)

Configuration:

```bash
NOTIFICATION_RATE_LIMIT_PER_SECOND=100
NOTIFICATION_RATE_LIMIT_RELEASE_SECONDS=1
```

## Observability Metrics (Step 6-7)

`/metrics` returns a JSON snapshot with:

- Notification counters: `created_total`, `sent_total`, `failed_total`, `retry_total`, `rate_limited_total`
- Per-channel counters: `created_by_channel`, `rate_limited_by_channel`
- Provider counters: `request_total`, `transient_failure_total`, `permanent_failure_total`, `avg_latency_ms`
- Queue depth gauges: `high`, `normal`, `low`

Example:

```bash
curl -sS http://localhost:8000/metrics | jq .
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
