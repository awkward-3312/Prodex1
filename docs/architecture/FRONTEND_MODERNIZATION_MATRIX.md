# Matriz de modernización del frontend de PRODEX

Complemento de [`FRONTEND_MODERNIZATION_AUDIT.md`](./FRONTEND_MODERNIZATION_AUDIT.md). Las cifras y la evidencia están allí; esta matriz resume decisiones por subsistema.

- **Dificultad:** 1 = mecánico, 10 = riesgo de negocio alto y sin red de seguridad.
- **Riesgo:** impacto si sale mal (Bajo / Medio / Alto / Crítico), no probabilidad.
- **Fase recomendada:** número de fase de la sección 18 de la auditoría (0 auditoría · 1 red de seguridad · 2 limpieza · 3 costuras y servicios · 4 ajustes compatibles con Vue 2.7 · 5 `@vue/compat` + Router 4 · 6 estado, i18n y librerías · 7 retirar BootstrapVue · 8 Vue 3 puro · 9 Vite · 10 TypeScript · 11 Bootstrap 5 opcional · 12 interfaz del POS).

| Subsistema | Tecnología actual | Objetivo recomendado | Dificultad 1-10 | Riesgo | Blockers | Prerequisitos | Fase recomendada |
|---|---|---|---|---|---|---|---|
| Framework | Vue 2.7.16 | Vue 3.x, primero vía `@vue/compat`, luego puro | 8 | Alto | slots viejos, vee-validate, `router-link`, scripts con `__vue__` | Fases 1–4 | 5 (compat) → 8 (puro) |
| Compilador SFC | `vue-loader` 15 + `vue-template-compiler` | `vue-loader` 17 + `@vue/compiler-sfc` (y `@vitejs/plugin-vue` en Vite) | 4 | Medio | `qrcode-scanner` con `template:` en runtime | Spike de build real | 5 |
| Router | vue-router 3.6.5, 467 registros, history | vue-router 4 | 6 | Alto | `router-link` roto bajo compat (645 usos), `addRoutes`, `path:"*"`, parche de `push`, 211 `tag/event/exact` | Snapshot de rutas (fase 1) | 5 (junto con compat) |
| Estado | Vuex 3.6.2, 6 módulos, 188 archivos con helpers | Vuex 4 → (opcional) Pinia | 3 | Bajo | ninguno (funciona bajo compat, probado) | E2E de login/permisos | 6 |
| Permisos en cliente | `currentUserPermissions.includes()` en 114 archivos (1 611 usos); sin `meta` en rutas | Composable `usePermissions()` + mapa de permisos por ruta | 4 | Alto | No hay mapa ruta→permiso; el servidor es la barrera real | Tests de permisos restringidos (`PxShellRestrictedUserProbesTest`) | 3 (composable), 6 |
| i18n | vue-i18n 8.28.2, traducciones en BD del tenant, 15 679 `$t` | vue-i18n 9 con `legacy: true` | 3 | Medio | fallo de idioma o RTL no aceptable | Capturas es/en/ar | 6 |
| Formato de fechas/monedas | `moment` (46 archivos), `Intl`, `priceFormat.js`, plugin de locales de moment | Conservar; luego `Intl`/`date-fns` | 4 | Medio | Posible recorte de locales de moment (verificar) | Pruebas de `priceFormat` (Vitest) | 6 |
| RTL | `bootstrap-rtl.scss` propio, `themeMode.rtl`, `dir` en `metaInfo` | Igual, después RTL nativo de BS5 si se adopta | 5 | Alto | Verificación visual manual hoy | Capturas ar/ur | 1 (gate), 11 |
| Componentes base UI | BootstrapVue 2.23.1 (5 825 tags, 198 archivos) + `px-next` (26 comp.) | Solo `px-next` | 6 | Alto | 53 modales por id; POS (127 `b-*`) | `PxToast`, `PxConfirm`, `PxModal` imperativo, `PxTooltip`, `PxDatePicker` | 7 |
| Notificaciones | `$bvToast.toast` (277 llamadas, 249 archivos) | `PxToast` servicio | 3 | Bajo | ninguno | Fase 3 servicio + codemod | 3 |
| Confirmaciones | `$swal` (369, 95 archivos) + `$bvModal.msgBoxConfirm` (11) | `PxConfirm` sobre SweetAlert2 | 3 | Bajo | plugin `vue-sweetalert2` no instala `$swal` bajo compat | Fase 3 | 3 |
| Modales | `b-modal` (112) + `$bvModal.show/hide` (236) | `PxModal` con API imperativa | 5 | Alto | POS, pagos | Tests E2E de flujos con modal | 3 (API), 7 |
| Tooltips/popovers | `v-b-tooltip` (116), `v-b-toggle` (12), `v-b-popover` (1) | Directiva `v-px-tooltip` | 3 | Bajo | hooks de directiva cambian en Vue 3 | Fase 3 | 3 |
| Formularios: campos | `b-form-group` / `b-form-input` / selects / checks | `PxField` + `PxInput` + `PxSelect` + `PxCheck` | 4 | Medio | validación acoplada | `PxValidation` | 4, 7 |
| Validación | vee-validate 3.4.15 (590 providers, 162 observers, ~123 archivos) | `PxValidation` propia (misma API de slots) sobre el núcleo `validate()` de vee-validate 4 o reglas propias | 7 | Alto | Fallo silencioso bajo compat | Diseño de capa + migración mecánica | 4 |
| Tablas | `vue-good-table` (73 archivos) + `b-table` (10) + `PxTable` (136 usos) | `PxTable` | 7 | Alto | Paridad de ordenamiento/paginación en servidor, selección, slots de fila (`slot-scope` rompe bajo compat) | Fase 3–4 | 6 |
| Selects con búsqueda | `vue-select` 3.20.4 (263 tags, 76 archivos) | `PxSelect`/combobox propio | 6 | Medio | Paridad: múltiple, tags, remoto | Auditoría de opciones usadas | 6 |
| Fechas (pickers) | `vue2-daterange-picker` (33), `vuejs-datepicker` (4), `b-form-datepicker` (4), `vue2-clock-picker` (2) | `PxDatePicker` + rango | 7 | Medio | Formato del tenant, RTL, moment | Fase 3–4 | 6 |
| Gráficos | `vue-apexcharts` 1.7.0 (24 archivos) + `echarts`/`echarts-gl` (1 vista) | `vue3-apexcharts` o wrapper propio; retirar `vue-echarts` | 3 | Bajo | ninguno conocido | Verificación visual | 6 |
| Iconos | `lucide-vue` vía `LucideIcon.vue` (279 archivos) | `lucide-vue-next` en un solo archivo | 2 | Bajo | render funcional | Ninguno | 6 |
| Meta/título | `vue-meta` 2.4.0 (`metaInfo`, 300 vistas) | Composable `usePageMeta()` o `@unhead/vue` | 4 | Medio | sin versión estable para Vue 3 | Codemod | 6 |
| Drag & drop | `vuedraggable` 2.24.3 (5 archivos) | `vuedraggable@next` (SortableJS) | 3 | Bajo | ninguno | — | 6 |
| Código de barras/QR | `vue-barcode` (7), `qrcode-scanner` (23 tags) + `qrcode.js` vendorizado, `qrcodejs` CDN | Wrapper propio con JsBarcode/`html5-qrcode` versionados | 5 | Medio | Global `Html5QrcodeScanner` sin `package.json`; CDN sin SRI | Fase 3 | 6 |
| Impresión | `vue-easy-print`, `vue-html-to-paper` (globales), `window.print` (33), ESC/POS, QZ | Servicio de impresión propio | 6 | Alto | POS y recibos | Fase 3 (servicio) | 12 |
| Bus de eventos | `window.Fire = new Vue()` (353 usos, 68 archivos, 40 eventos) | Emisor tipado propio (`mitt`) | 4 | Medio | Eventos `offline-sync:*` del POS | Inventario de eventos | 3 |
| Estilos base | Bootstrap 4.6.2 vendorizado (15 415 líneas) + BS-Vue CSS | Mantener BS4 como CSS; decidir BS5 después | 8 (BS5) | Alto | Inyector de branding con selectores BS4; utilidades `ml/mr/text-left…` (179+107+90 archivos) | Capturas es/en/ar | 11 (opcional) |
| Sistema de diseño | Dos capas de tokens: `--px-*` (458 usos) y `--pxn-*` (10 455 usos) | Una sola capa canónica (`--pxn-*`) con alias | 4 | Medio | 5 194 hex y 2 761 `!important` en estilos | Decisión de producto | 11 (o continuo) |
| Branding por tenant | `applyPrimaryColor()` inyecta CSS BS4 con `!important`; color en `localStorage.primaryColor` | Variables CSS (`--primary-color*`) consumidas por `px-next`; inyector mínimo | 6 | Crítico | Depende de clases `custom-control`, `page-link`, `.btn-primary` | Probar 2 tenants (subdominio y dominio propio) | 7 (parcial), 11 |
| Tema oscuro | `.dark-theme` (320 refs en 17 archivos + 3 temas SCSS) | Igual | 3 | Medio | — | Capturas dark | continuo |
| POS (interfaz) | `pos.vue` 19 318 LOC, `ModernPaymentModal` 2 627, 127 `b-*` | Igual funcionalmente; dividir en componentes y servicios | 10 | Crítico | Sin pruebas de frontend; offline; dinero | Fase 1 (E2E POS) + fase 3 (lógica pura) | **12 (al final)** |
| POS (lógica: totales, descuentos, cola offline, ubicación) | Dentro de `pos.vue`, `utils/index.js`, `globalOfflineSync.js`, `posOperationalLocationBridge.js` | Módulos JS puros con Vitest | 6 | Crítico | Sin tests hoy | Fase 1 | 3 |
| POS: atajos y escáner | `mixins/posKeyboardShortcuts.js` (501 líneas), 61 archivos con `keydown` | Composable `usePosShortcuts()` | 6 | Alto | Foco, `$refs`, `keydown` global | E2E de atajos | 12 |
| Offline / service worker | `public/sw.js` (248 líneas, cola `pos_offline_sales_v1` en `localStorage`) | Igual; el SW no depende de Vue | 3 | Crítico | Reglas: solo GET, `/api/*` nunca cacheado, `KILL_SWITCH` | Prueba offline en E2E | 1 (test), sin cambio |
| Customer display / Kitchen display | Entrypoint `customer-display.min.js` (Vue 2 + i18n), `KitchenDisplay` | Migrar como piloto de Vue 3 (aislado, sin router/store) | 3 | Medio | ninguno | E2E básico | 5 (piloto) |
| Scripts sueltos `prodex-*.js` | 23 archivos, 4 341 líneas, 18 con `MutationObserver`, 4 leen `el.__vue__` | Reemplazar por composables/eventos donde tocan Vue; el resto se mantiene | 6 | Alto | `__vue__` no existe en Vue 3; dependen del marcado | Inventario por script | 4 |
| Layout | `PxShell` (default), `largeSidebar/*` (legacy, 209 `router-link tag="a"`), `PortalLayout` | Solo `PxShell`; retirar legacy | 4 | Medio | Selector `getThemeMode.layout` | Confirmar que nadie usa el legacy | 2 |
| Código muerto | `old_pos.vue` (10 685 LOC), `create_sale.vue`, 33 rutas `classic/legacy`, `TableComponent.vue`, `arrowIcon.vue`, `StatusOverviewTab.vue` | Eliminar | 2 | Bajo | Confirmar cada uno | E2E + `git grep` | 2 |
| Dependencias sin uso | `@vee-validate/i18n`, `vue-echarts`, `vue-lazyload`, `vue-grid-layout`, `vue-navigation-bar`, `vue-simple-spinner`, `lodash.orderby`, `babel-polyfill`, `es6-promise`, `targets-webpack-plugin` | Eliminar | 1 | Bajo | ninguno | `npm ci` + build | 2 |
| Build | Laravel Mix 6.0.49 / webpack 5.105.3, sass-loader 8 (API legacy), 652 chunks prod | Mix con Vue 3 → Vite | 7 (Vite) | Alto | Contrato de salida, `require()`, `~` SCSS, `[hash]` global, artefactos versionados | Fase 5 estable | 5 (Mix), 9 (Vite) |
| Empaquetado de vendors | Sin `extract`; `jsPDF` en 81 chunks, `ApexCharts` en 24, `vue-good-table` en 88 | `splitChunks`/`mix.extract()` y servicio de PDF con import dinámico | 3 | Bajo | ninguno | — | 2 |
| Sass | 344 `@import`, 0 `@use`, 15 `~`, 234 avisos legacy-js-api | Sass moderno (`@use`) | 5 | Medio | Dart Sass 2.0 elimina la API legacy | Build estable | 9 |
| PWA | `sw.js` a mano, 3 manifests | Sin cambio (posible Workbox después) | 2 | Medio | — | — | fuera de alcance |
| TypeScript | No existe | Progresivo (`allowJs`) | 4 | Bajo | ninguno | Vite | 10 |
| Lint | No hay ESLint (sin binario ni configuración) | ESLint 9 + `eslint-plugin-vue` 9 (reglas `no-deprecated-*`) | 2 | Bajo | ninguno | — | 1 |
| Tests frontend | 0; 44 tests PHP leen fuente por texto | Playwright (E2E) + Vitest (utilidades) + snapshot de rutas | 5 | Alto | Sin tenant/servidor de prueba documentado | Datos demo estables | 1 |
| Tests PHP de arquitectura | 56 `*ArchitectureTest`; 8 Unit en rojo hoy | Mantener, actualizar por fase, reemplazar los que solo hacen regex de fuente | 4 | Medio | Se rompen con cambios de sintaxis | Política por test | 1 y en cada fase |
| CI | 7 workflows (PHPUnit selectivo, `node --check`, build prod en Node 20, aserción anti `_ui`) | Añadir E2E, lint, presupuesto de bundle, build doble | 3 | Bajo | ninguno | Fase 1 | 1 |
| Multitenancy | Stancl, BD por tenant; SPA única; `window.__planSummary`; dominios propios | Sin cambio; verificar en cada fase | 2 | Crítico | `localStorage` por origen; Blade inyecta globals | Un tenant subdominio + uno dominio propio + central | todas |
| Entrypoints secundarios | `login.min.js` (0.83 MB), `portal.min.js`, `customer-display.min.js`, `storefront.min.js` (Alpine + Tailwind) | Pilotos de Vue 3/Vite por ser aislados | 3 | Medio | `login.js` usa BootstrapVue y vee-validate | Fase 4 (validación) | 5 (piloto), 9 |
| Documentación de producto | `PRODUCT.md`: "Stack is fixed… not a framework migration" | Reescribir la restricción | 1 | Bajo | Decisión del dueño del producto | Aprobación | 1 |

## Orden resumido

```
0 Auditoría
1 Red de seguridad (E2E, snapshot de rutas, lint informativo, capturas es/en/ar)
2 Limpieza (código muerto, 10 dependencias sin uso, vendors compartidos)
3 Costuras y servicios (PxToast, PxConfirm, PxModal imperativo, PxTooltip, emisor de eventos, lógica pura del POS)
4 Ajustes compatibles con Vue 2.7 (slots a v-slot, .native, filters, PxValidation, sin __vue__, PxDatePicker/PxSelect/PxTable)
5 @vue/compat + Router 4 (build doble, canary)
6 Estado, i18n y librerías Vue 2-only
7 Retirar BootstrapVue por olas (POS al final)
8 Vue 3 puro
9 Vite
10 TypeScript progresivo
11 Bootstrap 5 (opcional)
12 Interfaz del POS (cierre)
```

Cambios frente al orden base propuesto: Router 4 entra con `@vue/compat` (fase 5), Vuex/i18n esperan a la fase 6, se añaden las fases 2 y 3 (limpieza y costuras) y Bootstrap 5 pasa a ser opcional y final. Detalle y evidencia en las secciones 15 y 18 de la auditoría.
