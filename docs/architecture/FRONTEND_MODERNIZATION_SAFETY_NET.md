# Red de seguridad de la modernización del frontend

Fase 1 de [`FRONTEND_MODERNIZATION_AUDIT.md`](./FRONTEND_MODERNIZATION_AUDIT.md). Base: `f138210`. Esta fase no cambia comportamiento del producto.

## Qué protege

| Protección | Cómo | Ejemplo de regresión que detecta |
|---|---|---|
| Flujos críticos en navegador real | Playwright + Chromium contra un tenant demo aislado | Un cambio de Vue, Router o BootstrapVue que rompa el login, el POS o la caja |
| Errores JS graves | Todo test falla ante `pageerror`, `console.error` o HTTP ≥ 500 / 404 de recursos propios | `slot-scope` que deja de renderizar y lanza una excepción |
| Mapa de rutas | Snapshot generado por AST desde `router.js`, `main.js` y `portal/router.js` | Una ruta perdida, renombrada, con otro `redirect` o con otro componente |
| Comportamiento offline del POS | Venta sin red → cola local → sincronización al volver | Una migración que pierda la cola `pos_offline_sales_v1` |

No protege: pixeles (no hay capturas visuales), i18n/RTL (solo se prueba `es`), ni flujos de facturación SAR, compras, ventas del panel o configuración. Ver "Limitaciones".

## Cómo ejecutarla

Requisitos: Node ≥ 20, PHP ≥ 8.2 con `vendor/` instalado, Docker (o un MySQL 8 propio), `npm ci`.

```bash
npm ci
npm run development            # el SPA compilado desde este árbol (los E2E prueban public/js)
npm run e2e:setup              # MySQL aislado en Docker, .env.e2e, tenant demo, usuarios, caja física
npx playwright install chromium   # una sola vez
npm run test:e2e               # toda la suite (28 tests)
npm run test:e2e:smoke         # solo los marcados @smoke (hoy son todos)
npm run test:e2e:headed        # con navegador visible
npm run test:e2e:routes        # snapshot de rutas; no necesita servidor ni BD
npm run test:e2e:routes:update # regenerar el snapshot tras un cambio de rutas intencional
```

`npm run development` **modifica archivos versionados** de `public/` (`main.min.js`, `mix-manifest.json`, …). No los incluyas en el commit: usa un worktree o restaura con `git checkout -- public && git clean -fd public/js public/css`.

`test:e2e` arranca el servidor PHP (`tests/e2e/scripts/serve.sh`, `php -S` con 4 workers) si no hay uno en el puerto; con `E2E_START_SERVER=0` usa el que ya exista en `E2E_BASE_URL`.

## Variables de entorno

Ninguna contraseña ni dominio está fijo en el repo. `e2e:setup` genera secretos aleatorios y los guarda en `tests/e2e/.env.e2e.local` (ignorado por git); en CI llegan por entorno.

| Variable | Por defecto | Uso |
|---|---|---|
| `E2E_DB_PASSWORD` | aleatoria | Contraseña root del MySQL aislado |
| `E2E_ADMIN_PASSWORD` / `E2E_RESTRICTED_PASSWORD` | aleatoria | Usuario administrador y usuario sin permisos |
| `E2E_ADMIN_EMAIL` / `E2E_RESTRICTED_EMAIL` | `e2e-admin@example.test` / `e2e-limited@example.test` | |
| `E2E_TENANT_SUBDOMAIN` | `e2e` | El tenant se identifica por subdominio: `http://e2e.localhost:8000` |
| `E2E_PORT` / `E2E_DB_PORT` | `8000` / `3307` | |
| `E2E_BASE_URL` | `http://<subdominio>.localhost:<puerto>` | Cambia el destino; los hosts que parecen producción se rechazan salvo `E2E_ALLOW_REMOTE=1` |
| `E2E_SKIP_DOCKER=1` | — | Usa un MySQL ya levantado (CI) |
| `E2E_RESET=1` | — | Destruye y recrea el contenedor y el tenant |

## Datos demo y aislamiento

- **MySQL 8.4 propio** (`tests/e2e/docker-compose.yml`, contenedor `prodex-e2e-mysql`, `tmpfs`). No toca `stocky-mysql`, `stocky_saas` ni ninguna BD de desarrollo.
- **`.env.e2e`** con `APP_ENV=e2e`: Laravel lo carga en lugar de `.env`. El `.env` de desarrollo no se lee ni se sobrescribe.
- `tests/e2e/scripts/provision.php` se niega a correr con `APP_ENV=production` o con un host de BD que no sea local. Usa los mecanismos del proyecto: seeders centrales, `ProvisionTenantWorkspace`, `php artisan prodex:seed-demo-tenant` (50 productos, clientes, HRM, etc.).
- Fixtures añadidos por la suite: usuario `e2e_restricted` (rol sin permisos), la caja física `E2E-01`, todos los permisos para el rol del administrador, y existencias holgadas (100 000 por producto) para que las ventas de los E2E no agoten el catálogo entre corridas. Los tests de caja y de POS offline abren/cierran caja y crean una venta en ese tenant; la caja queda cerrada al terminar.

## Tests implementados (28)

| Archivo | Tests | Qué comprueba |
|---|---|---|
| `auth.setup.js` | 2 | Sesión admin y restringida (se reutilizan como `storageState`) |
| `01-login` | 4 | Formulario, redirección sin sesión, credenciales inválidas, login válido |
| `02-dashboard` | 2 | Indicadores del panel y endpoints `/api/get_user_auth`, `/api/dashboard_data` |
| `03-navigation` | 3 | Los 8 módulos del menú, recarga de ruta profunda, URL inexistente |
| `04-permissions` | 5 | Usuario restringido: sin sucursal, `not_authorize` en productos/ajustes/POS, la API responde 403 |
| `05-inventory-transfers` | 4 | Catálogo, existencias por ubicación, traslados (listado → recepciones → nuevo), ajustes |
| `06-pos` | 4 | Carga, catálogo + carrito + "Restablecer", entrada por teclado/escáner (SKU + Enter), "Inicio" y volver |
| `07-cash-register` | 2 | Formulario de apertura; abrir → OPEN → recargar → cerrar con conteo por denominaciones → CLOSED |
| `08-pos-offline` | 2 | Offline/online sin perder el carrito; venta offline → cola `pending` → `synced` al volver la red |
| `routes/route-snapshot.js` | 1 comando | 474 rutas tenant (398 en producción, 403 con nombre, 43 redirects) y 20 del portal |

Los selectores usan clases y roles existentes (`.pos-wh-trigger`, `.pos-shell-register-pill`, `#OpenRegisterModal`, `nav[aria-label="Navegación principal"]`) y textos en español. **No se modificó ningún archivo del producto ni se añadió `data-testid`.**

## Errores preexistentes en la allowlist (`tests/e2e/support/allowlist.js`)

| Id | Qué es | Por qué no se corrige aquí |
|---|---|---|
| `transfer-logistics-insertBefore` | `resources/static/prodex-transfer-logistics.js:93` (`ensureHeaderButton`) llama `insertBefore` sobre un nodo que Vue ya movió; ≈ 11 excepciones por carga en toda pantalla `/app/*` | Cambiaría un script de producto. Se resolverá al reemplazar los scripts sueltos (fase 4 de la auditoría) |
| `stripe-frame-csp-report-only` | `console.error` de una CSP *report-only* al cargar `js.stripe.com` en el POS; el navegador no bloquea nada | Ajustar la CSP es una decisión de seguridad de producto |

Cada entrada acota cuántas veces puede ocurrir por test; si crece, el test falla.

## Baseline preexistente

Medido en un worktree limpio en `f138210` (sin cambios locales), Node 22, PHP 8.5.9.

| Verificación | Resultado en `f138210` | Regresión de esta rama |
|---|---|---|
| PHPUnit **Unit** | 1 321 tests, **4 fallos** | ninguna (la rama no toca PHP) |
| PHPUnit **Feature** | 934 tests, OK, 3 omitidos | ninguna |
| `npx mix` (development) | OK, 3 min 02 s (con carga de otras tareas); 234 avisos `legacy-js-api` de Sass | ninguna |
| `npx mix --production` | OK, 4 min 10 s; `main.min.js` 2.27 MB; mismos avisos de Sass | ninguna |
| Lint | No existe | — |

**Baseline failure** (los 4 fallan en `f138210` sin esta rama):
1. `PosSaleWarehouseInvariantArchitectureTest::test_location_native_pos_sale_cannot_be_rewritten_with_synthetic_warehouse_id`
2. `ShellDefaultLayoutArchitectureTest::test_excluded_fullscreen_routes_are_unchanged`
3. `ShellDomainCoverageArchitectureTest::test_every_admin_app_route_family_is_classified`
4. `TenantSchemaHealthServiceTest::test_it_reports_no_missing_requirements_when_modern_schema_exists`

**Regression introduced by this branch:** ninguna. Esta rama solo añade `tests/e2e/`, un workflow, tres scripts npm, la devDependency `@playwright/test`, `.gitignore` y el texto de `PRODUCT.md`.

**Corrección a la auditoría:** el árbol de trabajo sucio del autor mostraba 8 fallos Unit. Los otros 4 (`PosDraftLocationAuthorizationArchitectureTest`, `PosNativeSaleIntegrityArchitectureTest`, `PosSalesWarehouseNullableArchitectureTest`, `SalesLocationNativeArchitectureTest`) **no fallan en `f138210`**: los provocan los cambios locales sin commit (comentarios traducidos que rompen tests que buscan texto en el código). No son parte del baseline de la rama. Además, `router.js` tiene **465** registros de ruta, no 467: la auditoría contó dos objetos `{ path: "/app/pos" }` de los guards `next()`. Con las 9 rutas de `main.js` el total es 474.

**Baseline de producto encontrado al construir el entorno (no corregido):** aprovisionar un tenant desde cero falla en `f138210` con `Duplicate entry '1' for key 'permissions.PRIMARY'`. Las migraciones de tenant ya insertan 6 permisos (ids 1–6) y `PermissionsSeeder` inserta ids fijos 1–244. `provision.php` lo evita desplazando esas 6 filas a ids altos antes de sembrar, sin tocar el producto. Debe corregirse aparte: es un riesgo real para el alta de tenants nuevos.

**Builds sin contaminar el repo:** `mix` reescribe archivos versionados de `public/` y `CleanWebpackPlugin` vacía `public/js/*`. El build de desarrollo corrió en el worktree de la rama (sus cambios en `public/` no se commitean y se restauran al terminar); el de producción, en una copia fuera del repo.

## Limitaciones conocidas

- **Solo `es` y solo LTR.** No hay pruebas de otros idiomas ni de RTL (`ar`, `ur`). Es una condición previa de la fase 2 (ver abajo).
- **Sin cobertura** de SAR, compras, ventas del panel, configuración, HRM, reportes ni del cierre con diferencias de caja. El tenant demo no tiene sucursales ni ubicaciones, así que no se ejercita una transferencia completa (crear → despachar → recibir); solo la navegación y los formularios.
- **La venta offline usa efectivo y un producto**; no cubre lotes, series, descuentos, impresión, ESC/POS, QZ Tray, customer display ni kitchen display.
- Los textos se buscan en español y dependen de los datos demo (`Default Warehouse`, SKU `PR-DEMO2-054`). Los nombres de los productos demo cambian entre aprovisionamientos (el seeder elige una "persona" por hash del id del tenant); los SKU no, por eso los tests usan SKU.
- Chromium en escritorio (1440×900); sin móvil ni otros navegadores.
- La suite corre con 2 workers contra un servidor PHP embebido; el orden de los archivos importa solo en `07` y `08` (`describe.serial`, con limpieza propia).
- `route-snapshot.js` usa `@babel/parser` y `@babel/traverse`, que llegan como dependencia de `laravel-mix`. Si se sustituye Mix, hay que declararlos.

## CI

- **`route-snapshot`** (bloqueante, en `.github/workflows/frontend-safety-net.yml`): `npm ci` + `npm run test:e2e:routes`.
- **`e2e`**: escrito y **manual** (`workflow_dispatch`, `continue-on-error`). Levanta MySQL 8.4 como servicio, compila con `npx mix`, provisiona con `setup.sh` (`E2E_SKIP_DOCKER=1`) y ejecuta Playwright. **No se ha ejecutado en GitHub Actions**; el mismo flujo se validó en local con Docker. Falta: (1) una ejecución verde en Actions, (2) confirmar que `composer install` y el build caben en el tiempo del runner, (3) repetirlo dos veces para descartar flakiness. Después, pasarlo a `pull_request` y a bloqueante.
- Nada del flujo depende de producción ni del VPS.

## Condiciones antes de la siguiente fase

1. El job `e2e` verde en GitHub Actions dos veces seguidas (o decisión explícita de correr los E2E solo en local).
2. Los 4 fallos Unit del baseline corregidos o aislados, para que un rojo nuevo sea una señal.
3. Capturas o pruebas `ar` (RTL) y `en` de las pantallas críticas, o aceptación explícita de ese riesgo.
4. Decidir la política para los 44 tests PHP que leen código fuente frontend: se romperán con cada cambio de sintaxis.
5. Corregir aparte el fallo de aprovisionamiento de tenants (permisos duplicados).
6. Añadir a la suite, como mínimo, una venta con pago mixto o con lote/serie antes de tocar el POS.
