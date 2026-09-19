#!/usr/bin/env bash
# Servidor PHP embebido para los E2E (lo lanza Playwright `webServer`). Usa .env.e2e (APP_ENV=e2e).
#
# El servidor embebido con varios workers (PHP_CLI_SERVER_WORKERS, experimental en PHP) puede terminar con "Segmentation fault" bajo
# ráfagas de conexiones concurrentes (visto en GitHub Actions con PHP 8.3 al servir el bundle de desarrollo). Sin reinicio, todos los
# tests siguientes fallan con ERR_CONNECTION_REFUSED; con reinicio solo falla (y se reintenta) la petición en vuelo.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"
export APP_ENV=e2e
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

PHP_PID=""
trap 'if [ -n "$PHP_PID" ]; then kill "$PHP_PID" 2>/dev/null; fi; exit 0' TERM INT HUP

while true; do
  php -d "error_reporting=E_ALL&~E_DEPRECATED" -S "127.0.0.1:${E2E_PORT:-8000}" -t public server.php &
  PHP_PID=$!
  wait "$PHP_PID"
  echo "[serve.sh] el servidor PHP terminó (código $?); reiniciando" >&2
  PHP_PID=""
  sleep 0.3
done
