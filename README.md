# Event-Driven Notification System

Case study implementation for Insider One.

## What Is Built

- Notification Management API (single + batch create, status lookup, list/filter, cancel)
- Async queue processing with priority queues (`high`, `normal`, `low`)
- Idempotency support (single header and batch item key)
- Scheduled notifications with delayed dispatch and `pending` status
- Template system with variable substitution before enqueueing
- Docker runtime: one-command startup with API + worker + Redis
- Channel rate limiting (100 notifications/second/channel with delayed re-queue)
- Provider integration via webhook.site with retry and error classification
- Observability: `/metrics` endpoint + structured logs with correlation IDs

## Architecture Decisions

**SQLite over Postgres/MySQL** — sufficient for a case study; no infra to provision, migrations work identically.

**Separate Redis queues per priority** — Laravel worker drains `high` before `normal` before `low` because of queue order in `queue:work redis --queue=high,normal,low`. Simpler and more predictable than a single queue with a priority column.

**Error classification (transient vs permanent)** — 5xx/429/connection errors are retried (TransientProviderException); 4xx client errors are not (PermanentProviderException). This avoids burning retries on bad input.

**Retry policy: 4 attempts with backoff [5s, 30s, 120s]** — covers momentary outages without indefinite retries.

**Rate limiting via RateLimiter + job release** — jobs exceeding 100/sec/channel are re-queued with a 1-second delay instead of dropped. Enforced in the worker, not the API, to avoid blocking HTTP request handling.

**Scheduled notifications via delayed jobs** — future `scheduled_at` requests are stored as `pending` and dispatched with a queue delay so worker capacity is not wasted polling.

**Template rendering in the API layer** — a small config-backed template registry keeps the feature simple while still demonstrating variable substitution and channel-aware validation.

**Counter-based metrics in Cache** — lightweight, no Prometheus dependency needed for MVP. Redis-backed cache so counters survive restarts.

**Correlation ID as middleware** — every request gets an `X-Correlation-ID` (generated if missing). It propagates to the response header and is included in all structured log entries for end-to-end traceability.

## Architecture (Current)

- API: Laravel 13
- Database: SQLite
- Queue backend: Redis
- Worker: Laravel queue worker consuming `high,normal,low`

## How to run with Docker

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

## Set Up The Provider (webhook.site)

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
	"timestamp": "2026-06-13T06:30:00Z"
}
```

Without this, webhook.site may return HTML and the worker will treat it as transient failure and retry.

Custom actions and dynamic responses are possible with webhook.site's Rules feature, but the static response above is sufficient for testing the integration.

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

Provider setup

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

## Channel Rate Limiting

- Limit is enforced per `channel` (sms/email/push)
- Default is `100` messages per second per channel
- When limit is exceeded, job is re-queued with a short delay (`1` second by default)

Configuration:

```bash
NOTIFICATION_RATE_LIMIT_PER_SECOND=100
NOTIFICATION_RATE_LIMIT_RELEASE_SECONDS=1
```

## Observability

### Metrics

`/metrics` returns a JSON snapshot with:

- Notification counters: `created_total`, `sent_total`, `failed_total`, `retry_total`, `rate_limited_total`
- Rates: `success_rate`, `failure_rate` (0.0–1.0, null if no processed notifications yet)
- Per-channel counters: `created_by_channel`, `rate_limited_by_channel`
- Provider counters: `request_total`, `transient_failure_total`, `permanent_failure_total`, `avg_latency_ms`
- Queue depth gauges: `high`, `normal`, `low`

```bash
curl -sS http://localhost:8000/metrics | jq .
```

### Correlation ID

Every request includes an `X-Correlation-ID` response header. Pass your own or one is generated automatically:

```bash
curl -i -H "X-Correlation-ID: my-trace-123" http://localhost:8000/health
```

All structured log entries for that request include `correlation_id` alongside `notification_id`, `batch_id`, `channel`, and `attempt`.

Log event names: `notification.processing`, `notification.sent`, `notification.failed.transient`, `notification.failed.permanent`, `notification.failed.unexpected`, `notification.rate_limited`.

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

Create scheduled notification:

```bash
curl -X POST http://localhost:8000/api/notifications \
	-H "Content-Type: application/json" \
	-d '{
		"channel":"email",
		"recipient":"user@example.com",
		"content":"Reminder for tomorrow",
		"priority":"normal",
		"scheduled_at":"2026-06-14T09:00:00Z"
	}'
```

Create from template:

```bash
curl -X POST http://localhost:8000/api/notifications \
	-H "Content-Type: application/json" \
	-d '{
		"channel":"sms",
		"recipient":"+905551234567",
		"template_key":"welcome_sms",
		"template_variables":{
			"name":"Ada",
			"code":"123456"
		},
		"priority":"high"
	}'
```

List available templates:

```bash
curl http://localhost:8000/api/templates
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
- includes API behavior, validation, idempotency, scheduling, template rendering, queue dispatch, and job state transition coverage

## API Documentation

The OpenAPI specification is available at [docs/openapi.yaml](docs/openapi.yaml).

It covers:
- notification create/list/show/cancel endpoints
- batch lookup
- health and metrics endpoints
- request and response schemas used by the assessment
