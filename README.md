# Event-Driven Notification System

Case study implementation for Insider One.

## What Is Built

- **Notification Management API** (single + batch create, status lookup, list/filter, cancel)
- **Async queue processing** with priority queues (high, normal, low)
- **Idempotency support** (single header and batch item key)
- **Scheduled notifications** with delayed dispatch and pending status
- **Template system** with variable substitution before enqueueing
- **Redis-backed queue worker pipeline**
- **Channel rate limiting** (100 notifications/second/channel with delayed re-queue)
- **Provider integration** via webhook.site with retry and error classification
- **Observability**: metrics endpoint + structured logs with correlation IDs
- **Bonus local Dev Dashboard**: live metrics, health, logs, demo traffic, and in-browser test runner

## Architecture Snapshot

- **API**: Laravel 13
- **Database**: SQLite
- **Queue backend**: Redis
- **Worker**: Laravel queue worker consuming high,normal,low

## Quick Start (Recommended: Docker)

This is the clean, linear setup flow.

### 1. Prerequisites

- Docker Engine
- Docker Compose plugin (docker compose)

### 2. Create environment file

```bash
cp .env.example .env
```

### 3. Configure provider once (before startup)

1) Open https://webhook.site and copy your generated unique URL.

2) In webhook.site, configure a stable JSON response:
- Status code: 202
- Header: Content-Type: application/json
- Body:

```json
{
  "messageId": "provider-demo-id",
  "status": "accepted",
  "timestamp": "2026-06-13T06:30:00Z"
}
```

3) Update these keys in .env:

```dotenv
NOTIFICATION_PROVIDER_WEBHOOK_URL=https://webhook.site/<your-uuid>
NOTIFICATION_PROVIDER_TIMEOUT_SECONDS=10
NOTIFICATION_RATE_LIMIT_PER_SECOND=100
NOTIFICATION_RATE_LIMIT_RELEASE_SECONDS=1
```

Why this order matters: docker-compose reads variables from .env at startup, so you do not need a stop/start cycle just to apply provider configuration.

### 4. Start the stack

```bash
docker compose up --build
```

What starts:
- app on http://localhost:8000
- worker for async processing
- redis as queue backend

### 5. Verify system health

```bash
curl -i http://localhost:8000/health
curl -sS http://localhost:8000/metrics | jq .
```

### 6. Send a first notification

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

In webhook.site you should see a POST payload with to, channel, and content. If the worker is healthy, status should move to sent.

### 7. Stop

```bash
docker compose down
```

## Local Run (Without Docker)

### Prerequisites

- PHP 8.3+
- Composer
- SQLite
- Redis

### Setup

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Update provider configuration in .env:

```dotenv
NOTIFICATION_PROVIDER_WEBHOOK_URL=https://webhook.site/<your-uuid>
NOTIFICATION_PROVIDER_TIMEOUT_SECONDS=10
NOTIFICATION_RATE_LIMIT_PER_SECOND=100
NOTIFICATION_RATE_LIMIT_RELEASE_SECONDS=1
```

Run API:

```bash
php artisan serve
```

Run worker:

```bash
php artisan queue:work redis --queue=high,normal,low
```

## Bonus: Local Dev Dashboard

The project includes a local-only developer dashboard for live operational visibility during demos and debugging.

Access:

```text
http://localhost:8000/dashboard
```

Dashboard capabilities:
- **Metrics cards** (created, sent, failed, retries, rate-limited, success/failure rates, average provider latency)
- **Queue depth** (high, normal, low)
- **Health checks** (Redis, DB, worker heartbeat, provider webhook config)
- **Live event stream** with filters (correlation_id, notification_id, event, level)
- **Latest notifications table** with status and channel filters
- **Background test runner** with live output polling
- **Test runner groups**: smoke, notifications, queue, templates, provider, unit, load, and **all**
- **Demo traffic generator** (15-120 seconds)
- **Demo data cleanup** (demo notifications, event log, operational counters)

**Note**: dashboard routes are local-only and return 403 outside local environment.
`load` and `all` groups are intentionally heavier and may take noticeably longer to complete.

## Observability

### Metrics

GET /metrics returns:
- Notification counters: created_total, sent_total, failed_total, retry_total, rate_limited_total, cancelled_total
- Rates: success_rate, failure_rate
- Per-channel counters: created_by_channel, rate_limited_by_channel
- Provider counters: request_total, transient_failure_total, permanent_failure_total, avg_latency_ms
- Queue depth gauges: high, normal, low

```bash
curl -sS http://localhost:8000/metrics | jq .
```

### Correlation ID

Every request includes X-Correlation-ID in the response.
If not provided by client, it is generated automatically.

```bash
curl -i -H "X-Correlation-ID: my-trace-123" http://localhost:8000/health
```

Structured logs include correlation_id with notification_id, batch_id, channel, and attempt.

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

List templates:

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

## Testing

Run all tests:

```bash
php artisan test
```

Run optional high-throughput load tests (uses fake provider + Redis queue, no webhook.site calls):

```bash
RUN_LOAD_TESTS=true php artisan test --filter=HighThroughputLoadTest
```

Notes:
- **Load tests are disabled by default** to keep feedback fast.
- **Redis integration tests require Redis to be reachable** during local runs. Either keep Docker Redis running (for example `docker compose up -d redis`) or run a local Redis instance on `127.0.0.1:6379`.
- If Redis is not reachable, Redis integration tests are skipped by design.
- Coverage includes API behavior, validation, idempotency, scheduling, template rendering, queue dispatch, and job state transitions.

## API Documentation

OpenAPI specification:
- docs/openapi.yaml

Covers:
- notification create/list/show/cancel endpoints
- batch lookup
- health and metrics endpoints
- request and response schemas used by the assessment

## Architecture Decisions

SQLite over Postgres/MySQL:
- Fits case-study scope with minimal infrastructure overhead.

Separate Redis queues per priority:
- Worker order high,normal,low gives deterministic prioritization.

Error classification:
- Transient failures (5xx, 429, connection) are retried.
- Permanent failures (4xx validation/client errors) are not retried.

Retry policy:
- Up to 4 attempts with backoff 5s, 30s, 120s.

Rate limiting:
- Enforced in worker layer, not API layer, to avoid blocking request handling.

Scheduled notifications:
- Stored as pending and released as delayed jobs.

Template rendering:
- Applied before enqueueing with channel-aware validation.

Metrics model:
- Lightweight counters and gauges suitable for local/demo observability.

