#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
ADMIN_DIR="$ROOT_DIR/admin"
PORT="${PORT:-8080}"

if [[ ! -f "$BACKEND_DIR/artisan" ]]; then
  echo "Error: Laravel backend not found at $BACKEND_DIR (missing artisan)."
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "Error: php is not installed or not on PATH."
  exit 1
fi

while ss -ltn 2>/dev/null | grep -qE ":${PORT}\\b"; do
  PORT=$((PORT + 1))
done

printf "%s\n" "$PORT" >"$ROOT_DIR/.dev-port"

BACKEND_PID_FILE="$ROOT_DIR/.backend.pid"
QUEUE_PID_FILE="$ROOT_DIR/.queue.pid"
ADMIN_PID_FILE="$ROOT_DIR/.admin.pid"

if [[ -f "$BACKEND_PID_FILE" ]]; then
  EXISTING_BACKEND_PID="$(cat "$BACKEND_PID_FILE" || true)"
  if [[ -n "${EXISTING_BACKEND_PID:-}" ]] && kill -0 "$EXISTING_BACKEND_PID" 2>/dev/null; then
    EXISTING_CMD="$(ps -p "$EXISTING_BACKEND_PID" -o args= 2>/dev/null || true)"
    if echo "$EXISTING_CMD" | grep -q "php artisan serve"; then
      echo "Backend already running (PID: $EXISTING_BACKEND_PID)."
      echo "App:        http://localhost:$PORT"
      exit 0
    fi
  fi

  rm -f "$BACKEND_PID_FILE"
fi

(
  cd "$BACKEND_DIR"
  nohup php artisan serve --host=0.0.0.0 --port="$PORT" >"$BACKEND_DIR/storage/logs/dev-server.log" 2>&1 &
  echo $! >"$BACKEND_PID_FILE"
) &
wait

if [[ -f "$QUEUE_PID_FILE" ]]; then
  EXISTING_QUEUE_PID="$(cat "$QUEUE_PID_FILE" || true)"
  if [[ -n "${EXISTING_QUEUE_PID:-}" ]] && kill -0 "$EXISTING_QUEUE_PID" 2>/dev/null; then
    QUEUE_CMD="$(ps -p "$EXISTING_QUEUE_PID" -o args= 2>/dev/null || true)"
    if echo "$QUEUE_CMD" | grep -q "php artisan queue:"; then
      :
    else
      rm -f "$QUEUE_PID_FILE"
    fi
  else
    rm -f "$QUEUE_PID_FILE"
  fi
fi

if [[ ! -f "$QUEUE_PID_FILE" ]]; then
  (
    cd "$BACKEND_DIR"
    nohup php artisan queue:work --tries=3 --backoff=3 >"$BACKEND_DIR/storage/logs/queue-worker.log" 2>&1 &
    echo $! >"$QUEUE_PID_FILE"
  )
fi

if [[ -f "$ADMIN_DIR/package.json" ]]; then
  if ! command -v npm >/dev/null 2>&1; then
    echo "Warning: npm not found; skipping admin dev server."
  else
    (
      cd "$ADMIN_DIR"
      nohup npm run dev -- --host 0.0.0.0 >"$ROOT_DIR/.admin-dev.log" 2>&1 &
      echo $! >"$ADMIN_PID_FILE"
    )
  fi
fi

echo "App:        http://localhost:$PORT"
