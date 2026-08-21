# PRODEX — Arquitectura de inventario, Fase 1

## Objetivo

Separar de forma segura cuatro conceptos que históricamente estaban mezclados:

1. **Sucursal (`branches`)**: lugar donde opera el negocio.
2. **Almacén / Centro de distribución (`warehouses`)**: instalación logística dedicada y recurso limitado comercialmente por el plan.
3. **Ubicación de inventario (`inventory_locations`)**: lugar exacto donde físicamente puede existir inventario, por ejemplo Piso de venta, Bodega interna o Cuarentena.
4. **Stock**: la cantidad de producto. Durante Fase 1 sigue teniendo como fuente de verdad exclusiva `product_warehouse.qte`.

Fase 1 es estrictamente aditiva. No migra cantidades, no cambia POS, no cambia compras, no cambia ventas y no cambia la lógica de transferencias existente.

## Invariantes de seguridad

- `product_warehouse.qte` sigue siendo la fuente de verdad de stock durante toda la Fase 1.
- No se elimina ni renombra `warehouse_locations`.
- No se elimina ni renombra `product_warehouse_locations`.
- No se elimina `warehouse_id` de ventas, compras, transferencias, cajas, lotes, ecommerce ni reportes.
- No se crea stock nuevo en `inventory_locations` durante esta fase.
- Una `inventory_location` pertenece exactamente a **una sucursal o un almacén/CD**, nunca a ambos.
- Una ubicación de cuarentena nunca puede ser vendible.
- Solo ubicaciones de sucursal pueden ser `is_default_sales`.
- Las IDs nuevas usan `INT` firmado para compatibilidad con el esquema histórico de tenants.

## Modelo objetivo creado en Fase 1

```text
Empresa
├── Warehouse / CD
│   └── InventoryLocation
│       └── (stock llegará en Fase 2)
└── Branch / Sucursal
    ├── InventoryLocation: Piso de venta
    ├── InventoryLocation: Bodega interna
    └── InventoryLocation: Cuarentena
```

Campos principales de `inventory_locations`:

- `branch_id` nullable
- `warehouse_id` nullable
- `code`
- `name`
- `type`
- `is_sellable`
- `is_default_sales`
- `is_quarantine`
- `is_active`

Tipos iniciales soportados:

- `sales_floor`
- `storage`
- `quarantine`
- `damaged`
- `returns`
- `other`

## Compatibilidad con lo que ya existía

PRODEX ya tenía `warehouse_locations`, diseñado para posiciones internas de un warehouse. También existe `product_warehouse_locations`, que asigna un producto a una posición, pero no guarda cantidades y tiene unicidad por producto + warehouse. Esas tablas se conservan intactas.

La nueva `inventory_locations` no reemplaza esas tablas todavía. Se crea en paralelo para permitir que una sucursal tenga Piso de venta y Bodega interna sin convertirse artificialmente en un warehouse del plan.

## Auditoría de dependencias actuales de inventario

La búsqueda de referencias de `product_warehouse`, `warehouse_id` y `qte` confirma dependencia transversal. La migración futura debe realizarse por dominios, no mediante reemplazo masivo.

### Stock transaccional directo

Prioridad crítica:

- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/SalesController.php`
- `app/Http/Controllers/AdjustmentController.php`
- `app/Http/Controllers/DamageController.php`
- `app/Services/TransferLogisticsService.php`
- `app/Services/SafeTransferLogisticsService.php`
- `app/Services/TransferDispatchGuardService.php`
- `app/Http/Middleware/LockTransferDispatchStock.php`

Estos componentes pueden leer, bloquear, incrementar o disminuir cantidades y por tanto serán migrados únicamente después de introducir un `InventoryService` transaccional.

### Productos, lotes, seriales y conteos

- `app/Http/Controllers/ProductsController.php`
- `app/Http/Controllers/ProductBatchController.php`
- `app/Services/BatchService.php`
- `app/Services/SerialNumberService.php`

Los lotes y números de serie deberán mantener consistencia con la ubicación física, por lo que no pueden migrarse solo cambiando un `warehouse_id`.

### Ecommerce y disponibilidad pública

- `app/Http/Controllers/StoreFrontController.php`
- `app/Http/Controllers/Api/Store/CheckoutController.php`
- `app/Http/Controllers/Api/Store/OnlineOrdersApiController.php`
- `app/Services/Shopify/SyncService.php`
- jobs/comandos de WooCommerce

La disponibilidad pública necesitará una regla explícita basada en `is_sellable` y posteriormente en reservas; no debe sumar cuarentena o dañados.

### Dashboard y reportes

- `app/Http/Controllers/DashboardController.php`
- reportes de stock/warehouse
- reportes de ventas/compras por warehouse

Durante la transición, los reportes deberán poder reconciliar stock legacy contra stock por ubicación antes de cambiar la fuente de verdad.

### Usuarios y alcance

- `app/Http/Controllers/UserController.php`
- `user_warehouse`
- `UserOperationalAssignmentService`
- `WarehouseScopeService`

En fases posteriores el alcance evolucionará de warehouse a:

- sucursal operativa;
- ubicaciones de inventario permitidas;
- ubicación de inventario predeterminada;
- caja predeterminada.

La infraestructura legacy no se elimina hasta que todos los controladores de seguridad usen el nuevo modelo.

### Transferencias

El flujo logístico nuevo se conserva. En una fase posterior, origen/destino evolucionarán de Warehouse → Warehouse a InventoryLocation → InventoryLocation.

Se distinguirá:

- **Transferencia logística**: entre instalaciones/sucursales, con despacho, tránsito, QR y recepción.
- **Movimiento interno**: por ejemplo Bodega interna → Piso de venta dentro de una misma sucursal, sin necesidad de transporte.

## Estrategia de migración futura

### Fase 2

Crear stock por ubicación en una tabla paralela (`product_inventory_locations`) sin apagar `product_warehouse`.

### Fase 3

Crear una ubicación principal por cada warehouse legacy y hacer backfill controlado. Cada tenant deberá pasar reconciliación:

```text
SUM(product_warehouse.qte) == SUM(product_inventory_locations.quantity)
```

antes de activar nuevas escrituras.

### Fase 4

Habilitar sucursales con ubicaciones como Piso de venta, Bodega interna y Cuarentena.

### Fase 5

Migrar POS/cajas/usuarios hacia `default_branch_id` y `default_inventory_location_id` manteniendo compatibilidad legacy.

### Fase 6

Evolucionar asignaciones temporales de usuarios para que una cajera pueda ser trasladada temporalmente a otra sucursal y herede Piso de venta + caja de esa sucursal.

### Fase 7

Migrar transferencias y movimientos internos al nuevo motor de ubicaciones.

## Criterios de salida de Fase 1

Fase 1 queda terminada cuando:

- existe `inventory_locations` en todos los tenants;
- Branch y Warehouse exponen relaciones hacia InventoryLocation;
- existe un servicio único para crear/validar ubicaciones;
- el health-check tenant detecta ausencia del esquema;
- existen pruebas de propietario exclusivo, cuarentena y ubicación predeterminada;
- ninguna cantidad de inventario existente fue modificada;
- POS, ventas, compras y transferencias siguen leyendo `product_warehouse` exactamente como antes.
