# Red de seguridad de la modernización del frontend

Fase 1 de [`FRONTEND_MODERNIZATION_AUDIT.md`](./FRONTEND_MODERNIZATION_AUDIT.md), estabilizada en la rama `fix/frontend-modernization-baseline` (sobre `0e62690`). No migra Vue ni cambia el diseño; los pocos cambios de producto están listados en "Cambios de producto".

## Qué protege

| Protección | Cómo | Ejemplo de regresión que detecta |
|---|---|---|
| Flujos críticos en navegador real | Playwright + Chromium contra un tenant demo aislado | Un cambio de Vue, Router o BootstrapVue que rompa el login, el POS o la caja |
| Errores JS graves | Todo test falla ante `pageerror`, `console.error` o HTTP ≥ 500 / 404 de recursos propios | `slot-scope` que deja de renderizar y lanza una excepción |
| Mapa de rutas | Snapshot generado por AST desde `router.js`, `main.js` y `portal/router.js` | Una ruta perdida, renombrada, con otro `redirect` o con otro componente |
| Comportamiento offline del POS | Venta sin red → cola local → sincronización al volver | Una migración que pierda la cola `pos_offline_sales_v1` |
| Cobro real en el POS | Pago mixto, venta con serial y venta con lote contra el servidor | Un cambio que rompa el desglose de pagos o el descuento de lote/serial |
| Idiomas y RTL | es, en y ar con el selector real del POS | Un cambio que rompa la dirección RTL, desborde el layout o falle en otro idioma |
| Aprovisionamiento de tenants | `PermissionsSeederProvisioningTest` + el alta real del tenant E2E (sin workaround) | Volver a insertar permisos con ids fijos sin comprobar |

No protege: pixeles (no hay capturas visuales), ni flujos de facturación SAR, compras, ventas del panel o configuración. Ver "Limitaciones".

## Cómo ejecutarla

Requisitos: Node ≥ 20, PHP ≥ 8.2 con `vendor/` instalado, Docker (o un MySQL 8 propio), `npm ci`.

```bash
npm ci
npm run development            # el SPA compilado desde este árbol (los E2E prueban public/js)
npm run e2e:setup              # MySQL aislado en Docker, .env.e2e, tenant demo, usuarios, caja física
npx playwright install chromium   # una sola vez
npm run test:e2e               # toda la suite (36 tests, ~2.5 min, 1 worker)
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
- Fixtures añadidos por la suite (`provision.php`): usuario `e2e_restricted` (rol sin permisos), caja física `E2E-01`, `transfer_receive`/`transfer_issue_manage` asignados al rol del administrador, existencias holgadas (100 000 por producto y lote) y +300 seriales copiados de una fila real. Los tests de caja, POS y pagos abren/cierran caja y crean ventas en ese tenant; la caja queda cerrada al terminar.

## Tests implementados (36)

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
| `09-pos-payments` | 3 | Pago mixto (efectivo + tarjeta, dos pagos que suman el total); venta con serial (payload, serial consumido); venta con lote (payload, existencias del lote −1) |
| `10-languages` | 4 | es / en / ar: POS y shell cargan en el idioma, `dir` correcto (RTL solo en ar), menú lateral a la derecha en RTL, sin desbordes, 4 módulos navegables, idioma persistente tras recargar; volver a es devuelve LTR |
| `11-header-widgets` | 1 | Regresión: `prodex-transfer-logistics.js` no lanza `insertBefore` |
| `routes/route-snapshot.js` | 1 comando | 474 rutas tenant (398 en producción, 403 con nombre, 43 redirects) y 20 del portal |

Los selectores usan clases y roles existentes (`.pos-wh-trigger`, `.pos-shell-register-pill`, `.pos-shell-cart-row`, `#OpenRegisterModal`, `nav[aria-label="Navegación principal"]`, `#lang-dd`) y textos en español. **No se añadió ningún `data-testid`.**

**Un solo worker:** todos los specs comparten un tenant y varios tocan estado global (caja, idioma del tenant, ventas). Con 2 workers, `07` cerraba la caja mientras `09` cobraba.

**Lote y serial sí son reproducibles en el demo:** el seeder oficial crea `PR-DEMO2-001` (10 lotes) y `PR-DEMO2-002` (10 seriales). `provision.php` los amplía (lotes a 100 000 unidades, +300 seriales copiados de una fila real) para que las corridas repetidas no los agoten.

## Allowlist (`tests/e2e/support/allowlist.js`)

Solo queda una entrada:

| Id | Qué es | Por qué permanece |
|---|---|---|
| `stripe-frame-csp-report-only` | `console.error` de una CSP *report-only* al cargar `js.stripe.com` en el POS (`SecurityHeaders` emite `default-src 'self'` sin `frame-src`); el navegador no bloquea nada | Ajustar la CSP es una decisión de seguridad de producto; hoy el aviso es inocuo |

`transfer-logistics-insertBefore` **se corrigió y se eliminó** (ver "Cambios de producto"). Cada entrada acota cuántas veces puede ocurrir por test; si crece, el test falla.

## Baseline y estabilización

| Verificación | Resultado |
|---|---|
| PHPUnit **Unit** | **1 328 / 1 328** (1 321 originales + 7 de regresión del aprovisionamiento) |
| PHPUnit **Feature** | 934 OK, 3 omitidos |
| E2E local | 36 / 36, 3 corridas seguidas; y tras un reset completo del entorno |
| Route snapshot | OK (474 tenant, 20 portal) |
| `npx mix` (development) | OK |
| `npx mix --production` | OK, 3 min 44 s, `main.min.js` 2.27 MB (build en copia fuera del repo) |
| Lint | No existe |

**Causa raíz de los 4 fallos Unit de `f138210`** (ninguno era un fallo del producto de negocio):

| Test | ¿Falla el código o la expectativa? | Solución |
|---|---|---|
| `PosSaleWarehouseInvariantArchitectureTest` | El test. Las aserciones usaban comillas dobles y PHP interpolaba `$sale` (el contenido del archivo) dentro del literal, así que buscaban un texto imposible; además llamaba `base_path()` sin arrancar la app (fallaba al correr solo) | Comillas simples y ruta relativa al archivo. El contrato sobre `Sale.php` sigue intacto |
| `ShellDefaultLayoutArchitectureTest` | La expectativa. El commit `c28e5c07` sacó `/app/real-time-sales-counter` de las exclusiones a propósito (es una pantalla de consulta) | El test ahora exige que NO esté excluida y que las demás sigan excluidas |
| `ShellDomainCoverageArchitectureTest` | El código: esa ruta quedó sin clasificar en `SHELL_ROUTE_DOMAINS` | Se clasificó en el dominio `ventas` (donde ya estaba su entrada de menú) |
| `TenantSchemaHealthServiceTest` | La expectativa. El servicio ganó 47 requisitos y el fixture de "esquema moderno" quedó viejo | El fixture declara explícitamente todo el esquema exigido hoy |

**Unit en clon limpio:** además, 19 tests dependían de dos archivos de runtime que git no versiona (llaves OAuth de Passport y `storage/app/public/installed`) y fallaban en cualquier clon nuevo o en CI. `tests/bootstrap.php` (referenciado por `phpunit.xml`) los crea si faltan, solo bajo `storage/`, sin sobrescribir los existentes.

### Aprovisionamiento de tenants nuevos

**Causa raíz:** las migraciones de tenant `2026_08_20_220000`, `2026_08_20_220200` y `2026_08_21_090000` insertan permisos (`transfer_receive`, `transfer_issue_manage`, `branches_*`) con ids autoincrementales 1–6 **antes** de que `PermissionsSeeder` inserte sus ids fijos 1–244 (los referencia `PermissionRoleSeeder`). Un `insert` masivo sin comprobar nada terminaba en `Duplicate entry '1' for key 'permissions.PRIMARY'`. Solo afectaba a tenants nuevos; los antiguos ya tenían el catálogo sembrado antes de esas migraciones.

**Solución** (`database/seeders/PermissionsSeeder.php`, `PermissionRoleSeeder.php`):
- Si el **nombre** del permiso ya existe (con cualquier id) no se inserta: nunca hay duplicados.
- Si el **id** lo ocupa otro permiso, esa fila se reubica a un id libre conservando nombre, etiqueta, descripción y sus asignaciones en `permission_role` (la FK es RESTRICT, así que se copia, se repunta y se borra), y el permiso del catálogo toma su id.
- Volver a ejecutar el seeder en un tenant ya sembrado no cambia nada. `PermissionRoleSeeder` tampoco repite pares (permiso, rol).
- No cambia nombres ni semántica de permisos existentes. Los permisos de las migraciones quedan con ids 245+ (igual que en los tenants que los recibieron por migración).

**Pruebas:** `tests/Unit/PermissionsSeederProvisioningTest.php` (7 tests: tenant nuevo con filas de migraciones, idempotencia, tenant ya sembrado, asignaciones que siguen a la fila reubicada, nombre existente bajo otro id, `PermissionRoleSeeder` idempotente y tolerante). Fallan contra los seeders anteriores. Además, `provision.php` ya no lleva ningún workaround: usa `ProvisionTenantWorkspace` tal cual y el alta del tenant E2E (local y en GitHub Actions) es la prueba de integración.

Sigue vigente una decisión de producto, no un bug de esta corrección: `transfer_receive` y `transfer_issue_manage` no se asignan a ningún rol por defecto. La suite se los da al rol del administrador de pruebas como dato de prueba (`provision.php`), como haría un administrador desde la UI de roles.

## Cambios de producto

Mínimos y con prueba:

| Cambio | Archivo | Por qué |
|---|---|---|
| `dir="rtl"` según el idioma activo (`ar`, `ur`, `he`, `fa`) además del interruptor RTL heredado | `resources/src/App.vue` | Antes solo el interruptor del customizer activaba RTL, y el shell px-next no lo muestra: un tenant en árabe se veía en LTR. El layout ya se refleja bien con `dir="rtl"` |
| Clasificar `/app/real-time-sales-counter` en el dominio Ventas | `resources/src/views/app/_ui/data/shell-nav.js` | Ruta sin clasificar (test de cobertura) |
| `insertBefore` solo si el nodo es hijo directo del header | `resources/static/prodex-transfer-logistics.js` | Lanzaba `NotFoundError` ≈ 11 veces por carga. Se conserva el comportamiento visible (en el header px-next el botón nunca se montaba); mostrarlo allí sería una decisión de producto |
| Permisos idempotentes y tolerantes a filas previas | `database/seeders/Permission*Seeder.php` | Aprovisionamiento de tenants |

No se tocó Vue, Router, Vuex, BootstrapVue, Bootstrap, vee-validate, Vite ni TypeScript, ni el diseño.

### Hallazgos de idioma (no corregidos)

- El shell px-next tiene las etiquetas del menú **fijas en español** (`shell-nav.js`): en en/ar cambian el POS y las pantallas con `$t`, pero el menú lateral no. Por eso los tests de idioma comprueban el menú por su etiqueta española.
- `<html lang>` es siempre `es` (`App.vue`), aunque el idioma sea otro.
- Muchas cadenas de las pantallas nuevas no tienen clave de traducción: en en/ar se ven mezcladas con español (auditoría, sección 10).

**Nota heredada de la auditoría:** `router.js` tiene 465 registros de ruta, no 467 (la auditoría contó dos `next({ path: "/app/pos" })` de guards); con las 9 de `main.js` son 474.

## Limitaciones conocidas

- **Idiomas: solo `es`, `en` y `ar`** y solo dos pantallas (POS y shell), Urdu (`ur`) sin probar; no se comprueban textos por pantalla, porque muchas cadenas siguen en español (ver "Hallazgos de idioma").
- **Sin cobertura** de SAR, compras, ventas del panel, configuración, HRM, reportes, cierre con diferencias de caja, descuentos ni pagos con lote y pago mixto combinados. El tenant demo no tiene sucursales ni ubicaciones, así que no se ejercita una transferencia completa (crear → despachar → recibir); solo la navegación y los formularios.
- **La venta offline usa efectivo y un producto**; lote/serial/pago mixto se prueban solo en línea. No se cubren impresión, ESC/POS, QZ Tray, customer display ni kitchen display.
- Los textos se buscan en español y dependen de los datos demo (`Default Warehouse`, SKU `PR-DEMO2-054`). Los nombres de los productos demo cambian entre aprovisionamientos (el seeder elige una "persona" por hash del id del tenant); los SKU no, por eso los tests usan SKU.
- Chromium en escritorio (1440×900); sin móvil ni otros navegadores.
- La suite corre con 1 worker contra un servidor PHP embebido; `07`, `08`, `09` y `10` usan `describe.serial` con limpieza propia (cierran la caja, devuelven el idioma a español).
- `route-snapshot.js` usa `@babel/parser` y `@babel/traverse`, que llegan como dependencia de `laravel-mix`. Si se sustituye Mix, hay que declararlos.

## CI

`.github/workflows/frontend-safety-net.yml`:

- **`route-snapshot`** (bloqueante en el workflow): `npm ci` + `npm run test:e2e:routes`.
- **`e2e`**: MySQL 8.4 como servicio, PHP 8.3, Node 20, `npx mix`, `setup.sh` (`E2E_SKIP_DOCKER=1`) y Playwright. **No es un check requerido.** GitHub solo deja lanzar `workflow_dispatch` de un workflow que ya está en la rama por defecto; mientras este archivo no esté en `main`, el job también corre con los `push` a `fix/frontend-modernization-baseline`. Al llegar a `main` basta `workflow_dispatch` (o `pull_request`).
- Ejecuciones verdes consecutivas (36/36 E2E + route snapshot cada una):
  1. https://github.com/awkward-3312/Prodex1/actions/runs/35411033528 (commit `6caaafc`)
  2. https://github.com/awkward-3312/Prodex1/actions/runs/35411494307 (commit `c29aa68`)
- Nada del flujo depende de producción ni del VPS.

## Condiciones antes de la siguiente fase

1. Dos ejecuciones verdes consecutivas del job `e2e` en Actions (cumplido, ver arriba) y decidir cuándo volverlo requerido.
2. Decidir si el menú del shell, `<html lang>` y las cadenas sin clave de traducción entran en la fase de i18n o en la de limpieza (los tests de idioma dependen hoy de las etiquetas en español del menú).
3. Decidir la política para los 44 tests PHP que leen código fuente frontend: se romperán con cada cambio de sintaxis.
4. Antes de tocar el POS, ampliar la suite con descuentos, lote + pago mixto y cierre con diferencias.
5. `transfer_receive` / `transfer_issue_manage` sin rol por defecto: confirmar que es intencional.
