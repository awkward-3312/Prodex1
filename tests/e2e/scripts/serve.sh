#!/usr/bin/env bash
# Servidor PHP embebido para los E2E (lo lanza Playwright `webServer`). Usa .env.e2e (APP_ENV=e2e).
#
# Un solo proceso por defecto (sin PHP_CLI_SERVER_WORKERS): el modo multi-worker del servidor embebido es experimental en PHP y terminó con
# "Segmentation fault" en GitHub Actions (PHP 8.3) al servir el bundle bajo ráfagas de conexiones. Los E2E necesitan determinismo, no rendimiento
# (PHP rechaza PHP_CLI_SERVER_WORKERS=1: "number of workers must be larger than 1"). Si el servidor muere, el proceso termina (exec) y Playwright
# falla de forma visible: no hay reinicio silencioso. Para experimentos locales: PHP_CLI_SERVER_WORKERS=4 bash tests/e2e/scripts/serve.sh
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"
export APP_ENV=e2e
if [ "${PHP_CLI_SERVER_WORKERS:-1}" -gt 1 ]; then export PHP_CLI_SERVER_WORKERS; else unset PHP_CLI_SERVER_WORKERS; fi
exec php -d "error_reporting=E_ALL&~E_DEPRECATED" -S "127.0.0.1:${E2E_PORT:-8000}" -t public server.php
