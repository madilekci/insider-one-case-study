#!/usr/bin/env sh
set -eu

if [ ! -f .env ]; then
  cp .env.example .env
fi

upsert_env() {
  key="$1"
  value="$2"

  if grep -q "^${key}=" .env; then
    sed -i "s#^${key}=.*#${key}=${value}#" .env
  else
    echo "${key}=${value}" >> .env
  fi
}

if [ -n "${APP_KEY:-}" ]; then
  upsert_env "APP_KEY" "${APP_KEY}"
fi

upsert_env "QUEUE_CONNECTION" "${QUEUE_CONNECTION:-redis}"
upsert_env "REDIS_HOST" "${REDIS_HOST:-redis}"
upsert_env "REDIS_PORT" "${REDIS_PORT:-6379}"

if [ -n "${NOTIFICATION_PROVIDER_WEBHOOK_URL:-}" ]; then
  upsert_env "NOTIFICATION_PROVIDER_WEBHOOK_URL" "${NOTIFICATION_PROVIDER_WEBHOOK_URL}"
fi

upsert_env "NOTIFICATION_PROVIDER_TIMEOUT_SECONDS" "${NOTIFICATION_PROVIDER_TIMEOUT_SECONDS:-10}"

exec php artisan queue:work redis --queue=high,normal,low --sleep=1 --tries=3 --timeout=120
