#!/usr/bin/env bash
# Prepara el entorno E2E aislado: MySQL en Docker (o el servicio de CI), .env.e2e, migraciones centrales,
# tenant demo provisionado con los mecanismos reales del proyecto y usuario restringido.
#
#   bash tests/e2e/scripts/setup.sh            # local: crea contenedor y datos
#   E2E_SKIP_DOCKER=1 bash ...                 # CI/otro MySQL ya levantado en 127.0.0.1:$E2E_DB_PORT
#   E2E_RESET=1 bash ...                       # destruye y recrea el contenedor y el tenant
#
# Los secretos se toman del entorno; si faltan se generan al azar y se guardan en
# tests/e2e/.env.e2e.local (ignorado por git). No hay contraseñas fijas en el repo.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT"
LOCAL_FILE="tests/e2e/.env.e2e.local"

[ -f "$LOCAL_FILE" ] && set -a && . "$LOCAL_FILE" && set +a

rand() { openssl rand -hex 12; }
export E2E_PORT="${E2E_PORT:-8000}"
export E2E_DB_PORT="${E2E_DB_PORT:-3307}"
export E2E_TENANT_SUBDOMAIN="${E2E_TENANT_SUBDOMAIN:-e2e}"
export E2E_ADMIN_EMAIL="${E2E_ADMIN_EMAIL:-e2e-admin@example.test}"
export E2E_RESTRICTED_EMAIL="${E2E_RESTRICTED_EMAIL:-e2e-limited@example.test}"
export E2E_DB_PASSWORD="${E2E_DB_PASSWORD:-$(rand)}"
export E2E_ADMIN_PASSWORD="${E2E_ADMIN_PASSWORD:-$(rand)}"
export E2E_RESTRICTED_PASSWORD="${E2E_RESTRICTED_PASSWORD:-$(rand)}"

if [ ! -f "$LOCAL_FILE" ]; then
  umask 077
  cat > "$LOCAL_FILE" <<EOT
E2E_DB_PASSWORD=$E2E_DB_PASSWORD
E2E_ADMIN_PASSWORD=$E2E_ADMIN_PASSWORD
E2E_RESTRICTED_PASSWORD=$E2E_RESTRICTED_PASSWORD
EOT
fi

if [ "${E2E_SKIP_DOCKER:-0}" != "1" ]; then
  if [ "${E2E_RESET:-0}" = "1" ]; then docker compose -f tests/e2e/docker-compose.yml down -v; fi
  docker compose -f tests/e2e/docker-compose.yml up -d --wait
fi

# .env.e2e (solo se genera si no existe; APP_ENV=e2e hace que Laravel lo cargue en lugar de .env)
if [ ! -f .env.e2e ] || [ "${E2E_RESET:-0}" = "1" ]; then
  KEY="base64:$(openssl rand -base64 32)"
  sed -e "s|@@APP_KEY@@|$KEY|" -e "s|@@E2E_PORT@@|$E2E_PORT|" -e "s|@@E2E_DB_PORT@@|$E2E_DB_PORT|" \
      -e "s|@@E2E_DB_PASSWORD@@|$E2E_DB_PASSWORD|" tests/e2e/env.e2e.template > .env.e2e
fi

export APP_ENV=e2e
PHP=(php -d "error_reporting=E_ALL&~E_DEPRECATED")

docker_mysql() { docker exec -e MYSQL_PWD="$E2E_DB_PASSWORD" prodex-e2e-mysql mysql -uroot "$@"; }
if [ "${E2E_SKIP_DOCKER:-0}" != "1" ]; then
  docker_mysql -e "CREATE DATABASE IF NOT EXISTS prodex_e2e_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
else
  MYSQL_PWD="$E2E_DB_PASSWORD" mysql -h127.0.0.1 -P"$E2E_DB_PORT" -uroot -e "CREATE DATABASE IF NOT EXISTS prodex_e2e_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
fi

# Directorios de runtime que git no versiona (sesiones, caché, vistas, logs).
mkdir -p storage/framework/{sessions,views,testing} storage/framework/cache/data storage/logs bootstrap/cache

# Marcador de "instalado" que exige ServeSetupWhenNotInstalled (evita el asistente /setup).
mkdir -p storage/app/public && touch storage/app/public/installed

# Llaves de Passport (el SPA autentica /api con ellas; las lee AuthServiceProvider desde storage/).
[ -f storage/oauth-private.key ] || "${PHP[@]}" artisan passport:keys --force

"${PHP[@]}" artisan migrate --force
"${PHP[@]}" tests/e2e/scripts/provision.php
echo "Entorno E2E listo. Tenant: http://$E2E_TENANT_SUBDOMAIN.localhost:$E2E_PORT"
