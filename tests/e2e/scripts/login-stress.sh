#!/usr/bin/env bash
# Diagnóstico del fallo intermitente del servidor PHP embebido en CI: arranca el servidor en frío N veces y hace en cada una el mismo flujo
# que auth.setup (GET /login → POST /login → GET /app/dashboard) con curl, sin navegador ni build de JS. Sale con código != 0 si el servidor muere.
#   E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD / E2E_PORT / E2E_TENANT_SUBDOMAIN en el entorno; PHP_FLAGS opcional (p. ej. "-d zend.enable_gc=0").
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"
export APP_ENV=e2e
PORT="${E2E_PORT:-8000}"
HOST="${E2E_TENANT_SUBDOMAIN:-e2e}.localhost"
ROUNDS="${ROUNDS:-8}"
crashes=0
for i in $(seq 1 "$ROUNDS"); do
  # shellcheck disable=SC2086
  php -d "error_reporting=E_ALL&~E_DEPRECATED" ${PHP_FLAGS:-} -S "127.0.0.1:${PORT}" -t public server.php > "/tmp/php-stress-$i.log" 2>&1 &
  PID=$!
  for _ in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:${PORT}/robots.txt" && break; sleep 0.25; done
  JAR="$(mktemp)"
  RES="--resolve ${HOST}:${PORT}:127.0.0.1"
  PAGE="$(curl -s $RES -c "$JAR" -b "$JAR" "http://${HOST}:${PORT}/login")"
  TOKEN="$(printf '%s' "$PAGE" | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)"
  CODE="$(curl -s $RES -c "$JAR" -b "$JAR" -o /dev/null -w '%{http_code}' -L --max-time 60 \
    --data-urlencode "_token=${TOKEN}" --data-urlencode "email=${E2E_ADMIN_EMAIL}" --data-urlencode "password=${E2E_ADMIN_PASSWORD}" \
    "http://${HOST}:${PORT}/login")"
  sleep 0.5
  if kill -0 "$PID" 2>/dev/null && curl -s -o /dev/null "http://127.0.0.1:${PORT}/robots.txt"; then
    echo "round $i: server OK (login http=$CODE)"
    kill "$PID" 2>/dev/null; wait "$PID" 2>/dev/null
  else
    wait "$PID" 2>/dev/null; rc=$?
    echo "round $i: SERVER DIED rc=$rc (login http=$CODE)"; tail -3 "/tmp/php-stress-$i.log"
    crashes=$((crashes+1))
  fi
  rm -f "$JAR"
done
echo "crashes: $crashes / $ROUNDS  flags='${PHP_FLAGS:-}' php=$(php -r 'echo PHP_VERSION;')"
[ "$crashes" -eq 0 ]
