#!/usr/bin/env bash
# Servidor PHP embebido para los E2E (lo lanza Playwright `webServer`). Usa .env.e2e (APP_ENV=e2e).
#
# Un solo worker por defecto: el modo multi-worker del servidor embebido (PHP_CLI_SERVER_WORKERS > 1, experimental en PHP) terminó
# con "Segmentation fault" en GitHub Actions (PHP 8.3) bajo ráfagas de conexiones concurrentes al servir el bundle. Los E2E necesitan
# determinismo, no rendimiento. Si el servidor muere, el proceso termina (exec) y Playwright falla de forma visible: no hay reinicio
# silencioso. Se puede subir con PHP_CLI_SERVER_WORKERS=N para experimentos locales.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"
export APP_ENV=e2e
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-1}"
PHP_ARGS=(-d "error_reporting=E_ALL&~E_DEPRECATED" -S "127.0.0.1:${E2E_PORT:-8000}" -t public server.php)
# Diagnóstico (CI): con E2E_PHP_GDB=1 el servidor corre bajo gdb y, si PHP muere por una señal, imprime el backtrace en el log.
if [ "${E2E_PHP_GDB:-0}" = "1" ] && command -v gdb >/dev/null 2>&1; then
  exec gdb -q -batch -ex "handle SIGPIPE nostop noprint pass" -ex run -ex "bt 40" -ex "info sharedlibrary" --args php "${PHP_ARGS[@]}"
fi
exec php "${PHP_ARGS[@]}"
