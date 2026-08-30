# Fase B0 — Arquitectura de información y mapa de navegación

**Estado:** APROBADA. Decisiones D1–D8 definitivas (ver §8). No cambia la
navegación real de PRODEX — la arquitectura se implementa en una fase posterior.
**Artefactos:** `data/module-map.js` (inventario estructurado) · sección
`B0 · Mapa de navegación` en el playground `/app/_ui` · maqueta de shell
poblada con el mapa real.
**No es** la fuente de verdad de navegación: la arquitectura real se decide
**después** de aprobar B0. B1 (Dashboard real) y B2 (Productos real) son fases
aparte y no dependen de la implementación de navegación.

---

## 0. Método y límites

- **Sin telemetría.** No existe tracking de navegación/uso ni en frontend ni en
  backend (verificado: sin mixpanel/amplitude/segment/posthog/gtag;
  `navigationPerformance.js` sólo hace dedupe de locale + NProgress;
  `BusinessAuditService` es integridad de datos de negocio, no navegación;
  `tenants.last_activity_at` es "último acceso"). La columna **frecuencia** es
  **heurística** con base declarada por fila.
- **Fuentes leídas (solo lectura):** `Sidebar.vue` (2 786 líneas, ~159 destinos
  `/app/*`, ~31 grupos padre, ~129 permisos, ~23 flags de plan),
  `VerticalSidebar.vue` (árbol casi idéntico, divergencias menores),
  `main.js` (`router.addRoutes` — rutas de Organización/Inventario que **no
  están** en el sidebar real), `router.js`, y `prodex-navigation-v3.js` (un
  agrupamiento previo hecho a mano, tomado como referencia, no como verdad).
- **No se tocó:** `Sidebar.vue`, `VerticalSidebar.vue`, `TopNav*`,
  `friendlyNavigation.js`, `resources/static/prodex-*.js`,
  `store/modules/*Sidebar.js`, rutas reales, POS, pantallas reales, backend.

---

## 1. Mapa actual (resumen)

El sidebar actual es una lista **plana de ~40 ítems de primer nivel**, cada uno
con submenú, todos con `v-show` por permiso y muchos con `planFeature(...)`. No
es config-driven: son ~5 300 líneas de plantilla entre `Sidebar.vue` y
`VerticalSidebar.vue`, más una capa de scripts que reordena el DOM en runtime
(`prodex-navigation-v3.js`, `prodex-sidebar2-organizer.js`, etc.).

Peso por módulo (nº de destinos en el sidebar ≈ carga de sub-navegación):

| Módulo | Destinos | | Módulo | Destinos |
|---|---:|---|---|---:|
| reports | 40 | | service | 5 |
| settings | 22 | | commissions | 5 |
| products | 12 | | sales | 4 |
| hrm | 10 | | meeting | 4 |
| marketing | 8 | | assets | 4 |
| Store (tienda) | 8 | | purchases | 3 |
| People (clientes+proveedores) | 8 | | expenses / deposits | 3 c/u |
| recruit | 7 | | billing | 3 |
| accounting-v2 | 7 | | transfers/quotations/damages/bookings/adjustments/User_Management | 2 c/u |

**Ítems sueltos de primer nivel que en realidad son sub-flujos:**
`sale_return`, `purchase_return`, `promotions`, `subscription_product`,
`ai_reports`.

**Módulos que existen como ruta pero NO están en el sidebar real:**
`/app/organization/*` (sucursales, acceso de empleados, plantillas de rol) y
`/app/inventory/*` + `/app/operations/stock-intake` (arquitectura de inventario
multi-ubicación, fases 1–3). Están sólo vía `router.addRoutes` en `main.js`.

**Agrupamiento previo (`prodex-navigation-v3.js`):** ya define 8 grupos
(`Principal`, `Operación del negocio`, `Equipo`, `Gestión`, `Finanzas`,
`Crecimiento e integraciones`, `Análisis`, `Administración`) y **fusiona
ventas + compras + POS** en "Operaciones". Esta propuesta **separa Ventas y
Compras** (flujos distintos, ejes distintos) y **saca el POS** (ver §6).

---

## 2. Dominios de trabajo propuestos (por flujo, no por nombre)

| Dominio | Tesis | Flujo real |
|---|---|---|
| **Panel** | Estado del negocio de un vistazo | — |
| **Ventas** | Del presupuesto al documento fiscal y su devolución. Eje: el **cliente** | cotización → venta → factura fiscal (SAR/país) → cobro → devolución |
| **Compras** | Abastecerse. Eje: el **proveedor** | OC → recepción total/parcial → factura proveedor → devolución |
| **Inventario** | La arquitectura multi-ubicación de PRODEX (diferenciador) | catálogo → existencias por ubicación → ajuste / conteo / traslado / cuarentena / daño |
| **Contabilidad y finanzas** | El dinero + cumplimiento fiscal por país | asiento / gasto / depósito → conciliación → estados financieros → reportes fiscales |
| **RR. HH.** | Las personas del negocio | empleado → turno/asistencia → planilla · vacante → candidato → contratación |
| **Reportes** | Trabajo de análisis (rol dueño/analista). Muchos reportes también viven en su dominio | — |
| **Tienda en línea** | Canal e-commerce. Condicional por plan | publicar → pedido → confirmación de pago → cumplimiento |
| **Marketing** | Campañas / segmentos / plantillas / WhatsApp. Condicional por plan | segmento → campaña → envío → reporte |
| **Organización** | Estructura de la empresa (sucursales, almacenes, ubicaciones, usuarios, roles). Setup, no operación | sucursal/almacén → ubicaciones → usuarios → roles |
| **Gestión (add-ons)** | Proyectos, tareas, contratos, servicio, activos, reservas. Condicionales por plan | varía |
| **Configuración e integraciones** | Puesta a punto + Woo/Shopify/QuickBooks/ZATCA/webhooks | — |
| **Cuenta PRODEX** | Relación del tenant con PRODEX (plan, facturación, soporte, KB) — no con su negocio | — |
| **Punto de venta** | **Fuera del rediseño**. Modo a pantalla completa, no una página del shell | — |

**Descubrimientos clave (dominio real vs pantalla secundaria) → resueltos en §8:**

- **Clientes** y **Proveedores** estaban fusionados en "People" por parecido.
  Por flujo, Clientes → **Ventas**, Proveedores → **Compras** (**D2**).
- **Reportes** es hub *y* concern transversal (~45 permisos, muchos por dominio):
  distribuidos + índice global (**D1**).
- **Catálogo** vs **Existencias**: un solo dominio **Inventario** con "Catálogo"
  como grupo del panel (**D3**).
- **Organización** es un dominio real que **no está en la navegación actual**:
  Configuración/Más + riel extendido condicional (**D4**).
- **Facturación SAR** colgaba de **Productos**; va a Finanzas →
  "Cumplimiento fiscal", label/contenido por país, SAR = impl. HN (**D5**).

---

## 3. Matriz módulo por módulo

Ver `data/module-map.js` (`MODULES`) y la sección **B0 · Mapa de navegación →
pestaña "Inventario / matriz"** del playground. Cada fila lleva: label actual,
ruta, grupo/padre actual, origen (Sidebar / VSidebar / runtime-router / router /
nav-v3), permiso, flag de plan, nº de rutas, dominio, **ubicación propuesta**,
**justificación** y **frecuencia heurística** con su base.

Resumen de ubicaciones propuestas:

| Ubicación | Módulos (ejemplos) |
|---|---|
| **Riel** | Panel, Ventas, Inventario, Compras, Contabilidad y finanzas, Reportes, RR. HH.* |
| **Riel extendido** (si aplica) | Tienda en línea, Marketing, Organización |
| **Panel contextual** | devoluciones, cotizaciones, clientes, proveedores, ajustes, traslados, daños, series, existencias/ubicaciones, gastos, depósitos, cuentas, comisiones, SAR, reclutamiento, WhatsApp, AI reports, contador en tiempo real |
| **Configuración** | Settings (22), WooCommerce, Shopify, Organización†, Usuarios y acceso |
| **Cuenta PRODEX** | Plan y facturación, Soporte, Base de conocimientos |
| **"Más herramientas"** | Proyectos, Tareas, Contratos, Servicio, Activos, Reservas, Bienes raíces, Producto de suscripción, Reuniones |
| **Acceso POS (aparte)** | Punto de venta, Customer Display, Pantalla de cocina |

\* RR. HH. en riel compacto por peso estructural; ver **D6**.
† Organización: su lugar final (Config vs riel extendido vs módulo propio) es **D4**.

---

## 4. Riel: compacto vs extendido

**No hay regla fija de 8–12.** Se comparan dos alternativas (visibles en la
maqueta del shell, selector "Compacto / Extendido").

### Riel compacto — BASE PREDETERMINADA APROBADA (B0)

`Panel · Ventas · Inventario · Compras · Finanzas · Reportes · RR. HH.`
+ `Configuración` (engranaje) y `Más` fijados al pie.

- **Panel · Ventas · Inventario · Compras** siempre visibles.
- **Finanzas** (plan `accounting`), **Reportes** (≥1 permiso de reporte),
  **RR. HH.** (plan `hrm` + permiso — **D6**) aparecen sólo si el plan/permiso
  los habilita. Los 4 fijos no reordenan cuando los condicionales aparecen/desaparecen.
- Iconos inequívocos; panel contextual manejable (~3 grupos / ~12 ítems).

### Riel extendido — CONDICIONAL a módulos/estructura (no manual)

Compacto + `Tienda en línea` (plan `online_orders` activo) + `Marketing`
(plan `marketing` activo) + `Organización` (estructura multi-sucursal /
multi-almacén — **D4**).

- **No es un conmutador estético.** Estos dominios aparecen automáticamente al
  pie del riel cuando su plan está activo o la estructura lo justifica.

### Requisito futuro del riel (a verificar en la implementación real)

- Cada icono lleva **tooltip en hover Y en focus** (no sólo hover).
- Cada icono lleva **`aria-label`** con el nombre del módulo.
- **Estado activo inequívoco:** no basta el color — marca de posición (barra
  lateral) + fondo + peso, y `aria-current="page"`.
- Orden estable entre sesiones; navegable por teclado con foco visible.

### Criterios evaluados

| Criterio | Compacto (7–9) | Extendido (10–12) |
|---|---|---|
| Carga cognitiva | se abarca de un vistazo | escaneo secuencial |
| Reconocibilidad de iconos | alta (todos claros) | media (Organización, Suscripciones ambiguos) |
| Necesidad de tooltips | sólo hover | permanente recomendable |
| Longitud del panel contextual | 3 grupos / ~12 ítems | igual (los dominios no cambian) |
| Frecuencia de cambio entre módulos | óptima para el ciclo diario | añade ruido si esos dominios no se usan |

**Decisión B0:** riel **compacto** como base predeterminada; el extendido es
**condicional** a módulos/estructura habilitados (no una opción manual). Nunca
15–20 iconos.

---

## 5. Panel contextual de los 4 dominios complejos

Definido en `module-map.js` (`CONTEXT_PANELS`) y navegable en la maqueta
(selector "Panel contextual"). Resumen:

### Ventas
- **Operar:** Nueva venta · Ventas · Devoluciones · Cotizaciones *(plan)*
- **Cartera:** Clientes · Pagos pendientes · Contador en tiempo real
- **Configurar:** Promociones *(plan)* · Recibo del POS
- *Reportes inline:* Ventas por sucursal, Top clientes, Descuentos, Devoluciones (ratio)

### Inventario
- **Existencias:** Existencias por ubicación · Faltantes/diferencias · Conteo de stock
- **Movimientos:** Recepción de stock · Ajustes · Traslados *(plan)* · Daños
- **Catálogo:** Productos · Categorías/marcas/unidades · Lotes y vencimientos · Códigos de barra · Números de serie · Importar/actualizar
- *Reportes inline:* Kardex valorizado, Rotación, Antigüedad, Stock negativo, Por vencer

### Compras
- **Operar:** Nueva OC · Compras · Devoluciones a proveedor · Importar compras
- **Abastecimiento:** Proveedores · Pagos a proveedores
- *Reportes inline:* Compras por proveedor, Top proveedores, Pagos de compra, Alertas de cantidad

### Contabilidad y finanzas
- **Contabilidad:** Panel contable · Catálogo de cuentas · Asientos de diario · Balanza/ER/Balance · Reportes fiscales
- **Tesorería:** Gastos · Depósitos · Cuentas · Transferencia de dinero
- **Comercial:** Comisiones *(plan)*
- **Cumplimiento fiscal** *(D5 — label y contenido por país; implementación HN: SAR)*
- *Reportes inline:* Flujo de caja, Estado de resultados, Balance general, Resumen de impuestos

---

## 6. Tratamiento del POS

**Pregunta que B0 responde:** *¿desde dónde accede un usuario administrativo al POS?*

- **Hoy:** `Ventas → (submenú) → "Punto de venta"` (`router-link` a `/app/pos`,
  línea 1326 de `Sidebar.vue`), junto a "Customer Display" y "Contador en tiempo
  real".
- **Propuesta:**
  1. El POS es un **modo a pantalla completa**, no una página del shell. **No
     entra al riel ni a un panel contextual.**
  2. Acceso: botón dedicado **"Abrir POS"** en el topbar (visualmente distinto —
     es un cambio de contexto), y opcionalmente un ítem al pie del riel marcado
     como "abre en pantalla completa".
  3. **Customer Display** y **Pantalla de cocina** son pantallas auxiliares del
     POS: se acceden desde la configuración del POS o desde el propio POS.
  4. **No se rediseña su navegación interna. No se tocan sus rutas, componentes
     ni comportamiento.** El rediseño del POS es una fase independiente.

---

## 7. Módulos condicionales por plan / permiso

**Principio:** el riel nunca muestra un módulo que el plan o el permiso del
usuario no habilita. Se decide en render, no con CSS.

| Caso | Tratamiento |
|---|---|
| Módulo core sin plan (p. ej. Contabilidad sin plan `accounting`) | No aparece; el riel compacto puede quedar en 6 ítems para un plan básico |
| Módulo de riel extendido inactivo (Tienda / Marketing / Organización) | No se muestra; el riel extendido sólo existe para quien lo usa |
| Add-ons de "Gestión" (proyectos, tareas, contratos, servicio, activos, reservas, bienes raíces, suscripción, reuniones) | Zona **"Más herramientas"** al pie del riel. Sólo los habilitados. Nunca al riel principal |
| Integraciones (Woo / Shopify / QuickBooks / ZATCA / WhatsApp) | **Configuración → Integraciones**. Nunca son módulos de riel |
| Permiso parcial (usuario ve 2 de 22 rutas de Configuración) | El módulo aparece; el panel lista sólo lo permitido; si queda vacío, el módulo no aparece |

---

## 8. Decisiones B0 — RESUELTAS (definitivas)

| # | Tema | Decisión |
|---|---|---|
| **D1** | Reportes | **Distribuidos** por dominio (ventas→Ventas, stock→Inventario, finanzas→Finanzas) **+ índice global "Todos los reportes"** como módulo de riel. Fuera de B0: un pase de mapeo de los ~45 permisos de reporte a dominios. |
| **D2** | Clientes / Proveedores | **Separados.** Clientes → dominio **Ventas**; Proveedores → dominio **Compras**. Se disuelve "People". |
| **D3** | Catálogo / Existencias | **Un único dominio Inventario.** "Catálogo" es un **grupo contextual** dentro de Inventario, no un módulo de riel. |
| **D4** | Organización | **No** pertenece al riel compacto. Dominio administrativo estructural accesible desde **Configuración/Más**; aparece en el **riel extendido** cuando la estructura multi-sucursal del tenant lo justifica. La operación cotidiana de stock/ubicaciones permanece en **Inventario**. |
| **D5** | Cumplimiento fiscal | Se mueve conceptualmente a **Finanzas → "Cumplimiento fiscal"**. Label y contenido dependen del país. **SAR = implementación Honduras únicamente.** |
| **D6** | RR. HH. | **Permanece en el riel compacto** cuando el plan/permiso lo habilite. |
| **D7** | Ítems sueltos de primer nivel | `sale_return`, `purchase_return`, `promotions`, `subscription_product`, `ai_reports` → **recolocados en sus dominios** (paneles contextuales / "Más"). |
| **D8** | Fusión Ventas + Compras + POS | **Descartada definitivamente.** Ventas y Compras son dominios diferentes. POS es un contexto separado — se accede con **"Abrir POS"** desde el topbar (cambio de contexto a pantalla completa); **no se implementa en esta fase.** |

---

## 9. Qué NO se hizo (fuera de alcance de B0)

- No se modificó ninguna navegación real ni ruta real.
- No se tocó POS (ni rutas, ni componentes, ni navegación interna).
- No hay pantallas reales nuevas (B1/B2 son fases aparte).
- No hay backend, ni BD, ni commit/push/deploy.
- `module-map.js` **no** es todavía la fuente de verdad de navegación.
