# Migración de identidad PRODEX

## Auditoría antes de implementación

Base: `main`, `e2237464`. Había 22 archivos `public/js/prodex-*.js` sin seguimiento; se preservan. Rama de trabajo: `feat/prodex-brand-refresh`.

- Vue 3 usa Bootstrap 5 / BootstrapVueNext. `_variables-theme.scss` aún define el primario violeta. `store/modules/config.js` inyecta estados de botones, controles, navegación y loaders, y conserva la personalización local del primario.
- `prodex/_tokens.scss`, `_foundation.scss` e `_interactions.scss` componen la capa global. `px-next/_tokens.scss` alimenta los componentes actuales y se deriva del mismo primario runtime. `compat/*` conserva contratos de componentes y teleports.
- Auth tenant usa `public/css/auth.css`; auth central, landing, facturación y superadmin tienen hojas en `public/assets_super/css`. No reciben automáticamente el tema Vue.
- Se encontraron violeta `#663399`, indigo de auth, verde del superadmin y navy/cyan `#0F2A4A` / `#06B6D4`. Los colores de gráficos, categorías de reuniones y estados semánticos se distinguen de la marca, y no admiten reemplazo ciego.
- `Tenant::loginLogoUrl()` prioriza `login_logo_path`, luego `GeneralSetting::tenant_logo_path`, luego el asset de plataforma. La configuración tenant, imágenes de documentos y tienda deben conservar prioridad. Los iconos PWA también resuelven primero el archivo tenant.
- Seis páginas SEO estáticas conservaban favicon y vista social de la identidad anterior; ahora usan el icono oficial y cargan los tokens. El archivo social antiguo se conserva por si existe una URL publicada que aún lo solicite.
- La suite E2E existente usa un tenant demo aislado. Al comenzar no existían `.env.e2e` ni credenciales E2E locales; Docker no estaba ejecutándose. El servidor existente respondió 500 en `/login` antes de cambios.

## Composición accesible

Contraste WCAG calculado con luminancia sRGB: Ink/Aqua 8,00:1; blanco/Ink 14,62:1; blanco/Aqua 1,83:1. Ink es el primario estructural y de texto; Aqua se usa para acentos y selección con texto Ink. Los estados de error, éxito, advertencia e información conservan su semántica.

## Fuente de verdad

`resources/brand/prodex.json` contiene únicamente la paleta oficial y las rutas de assets. Laravel lo lee mediante `config/brand.php`, Vue lo importa y `scripts/generate-brand.mjs` genera los adaptadores CSS/SCSS para las capas existentes. Los tokens `px` y `pxn` conservan sus nombres y responsabilidades.

Los cinco PNG en `public/images/brand-assets` son copias exactas de los originales; no se recrean ni recolorean.

## Validación y límites conocidos

- Las pruebas nuevas fijan los hashes SHA-256 y dimensiones de los cinco originales y el contraste de combinaciones reales. Las variantes técnicas de 16, 32, 180, 192 y 512 px se generan del app icon oficial sin modificarlo.
- Los logos configurados por un tenant conservan prioridad. Solo se sustituyen fallbacks vacíos y archivos cuyos bytes coinciden con assets legacy conocidos; PWA, favicon y documentos usan la misma resolución.
- Los CSS de temas históricos (`lite-purple`, `dark-purple`, `lite-blue`) permanecen en el árbol porque no son entradas activas de las vistas auditadas; no se borran sin confirmar dependencias externas. El tema oscuro preexistente conserva reglas históricas, pero no se añadió un modo nuevo. Los colores de categorías, gráficos (incluido Sales3D) e indicadores de estado siguen siendo semánticos, no tokens de marca.
- La suite E2E de componentes (`/app/_ui?probe=ui`) requiere el bundle de desarrollo: la ruta de sonda se elimina explícitamente del bundle de producción. La verificación de producción se hace por compilación y por pruebas de marca sobre páginas reales.

## Cierre del release gate: instrumentación y placeholders

La ruta `/app/_ui` se registra únicamente cuando `NODE_ENV !== production`. En esa vista, `window.__pxProbe` monta componentes de prueba y permite inspeccionar sus estados y eventos; `window.__pxCutover` comprueba las reglas Bootstrap 5 y sus estilos calculados. Ninguna página de usuario depende de esas variables. Las suites 32, 33 y 34, más dos casos de la 36, son instrumentación de desarrollo. La suite completa mezclaba esos casos con las pruebas de producto y el release gate la ejecutó contra el bundle de producción; por eso los 99 casos esperaban variables que deliberadamente no existen allí.

`npm run test:e2e:instrumentation` ejecuta esos casos contra un bundle de desarrollo. `npm run test:e2e:production` ejecuta los casos de producto contra el bundle de producción. Ambos comandos incluyen el proyecto compartido de autenticación de Playwright; las pruebas con `@instrumentation` no están omitidas ni eliminadas. `npm run test:e2e` sigue ejecutando la suite completa en CI, que compila en modo desarrollo. El release gate local debe compilar cada bundle antes de su suite correspondiente y usar el tenant demo aislado.

Los cinco `images/tenant-default/*/no-image.png` ahora contienen exactamente el nuevo placeholder de plataforma. El provisionamiento nuevo ya copia esa plantilla a rutas por tenant. Las URLs existentes resuelven `images/tenants/{id}/{carpeta}/no-image.png`, por lo que centralizar el archivo implicaría cambiar contratos de URL y resolución de almacenamiento. La migración `2026_09_30_000001` actualiza las copias existentes solo si su SHA-256 coincide con uno de los dos placeholders heredados conocidos. Ignora cualquier archivo personalizado, ausente o enlazado; puede repetirse sin alterar archivos ya actualizados. `php artisan prodex:sync-no-image --dry-run` audita el resultado y el comando sin esa opción permite volver a aplicarlo si aparecen workspaces heredados después de la migración.

En el CSS central servido directamente, los controles primarios y sus estados claros/oscuros usan `--color-primary` y sus tonos derivados, que respetan la elección del personalizador. Permanecen los violetas de categorías CMS, indicadores de aprovisionamiento, seguridad/base de datos, avatares y pasos manuales, además de colores de código e información que no son marca principal.

## Resultado de la validación local

- `npm run production`: compilación correcta. `npm run brand:check` y `git diff --check`: correctos.
- `npm run test:frontend`: 169/169. PHPUnit de marca, login tenant, controlador de branding y sitio público: 34/34 (con avisos de deprecación de PHP/PHPUnit existentes).
- Suite E2E completa con bundle de desarrollo: 341 aprobadas, 1 omitida y 4 fallidas por carga de página/socket en una ejecución de 1,7 h. La repetición de los archivos completos que contenían esos cuatro casos pasó 25/25 con sesión nueva. El bundle final de producción pasó 10/10 E2E de POS y marca.
- 60 capturas reales iniciales cubrieron 15 vistas en 1440 LTR/RTL, 768 y 390 px; la revisión del bundle final volvió a capturar login, panel, formulario y POS (16 vistas/tamaños) y el POS tras el último ajuste (4). Se revisaron manualmente login tablet, dashboard desktop, formulario móvil y POS móvil. La captura final del POS pasó en los cuatro tamaños/direcciones.
