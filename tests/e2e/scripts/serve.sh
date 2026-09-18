#!/usr/bin/env bash
# Servidor PHP embebido para los E2E (lo lanza Playwright `webServer`). Usa .env.e2e (APP_ENV=e2e).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"
export APP_ENV=e2e
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php -d "error_reporting=E_ALL&~E_DEPRECATED" -S "127.0.0.1:${E2E_PORT:-8000}" -t public server.php
