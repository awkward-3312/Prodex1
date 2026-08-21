# PRODEX — Arquitectura de inventario, Fase 2 y transición controlada

## Objetivo

Introducir el motor de stock por ubicación sin apagar ni alterar todavía la fuente productiva histórica `product_warehouse.qte`.

La transición queda diseñada para realizarse por almacén/CD y por dominio funcional, no mediante un cambio global.

## Componentes nuevos

### `inventory_location_stocks`

Mantiene stock físico por `InventoryLocation` y por producto/variante:

- `quantity`: existencia física.
- `reserved_quantity`: existencia comprometida.
- disponible = `quantity - reserved_quantity`.
- `manage_stock`.

### `inventory_location_movements`

Ledger de movimientos del motor nuevo. Registra:

- tipo de movimiento;
- producto y variante;
- origen/destino;
- cantidad;
- usuario;
- referencia funcional;
- idempotencia;
- metadata.

Los movimientos son históricos. Una corrección se representa mediante otro movimiento, no editando el movimiento original.

### `InventoryService`

API transaccional única para el nuevo motor:

- aumentar;
- disminuir;
- mover;
- reservar;
- liberar reserva;
- consumir reserva;
- ajustar a cantidad física.

Utiliza locks de base de datos y orden de bloqueo determinista para reducir condiciones de carrera.

### `LegacyInventoryReconciliationService`

Compara cada Warehouse/CD histórico contra su `InventoryLocation` principal y permite backfill controlado.

El backfill se niega a continuar si:

- hay cantidades legacy negativas;
- la ubicación nueva ya contiene datos diferentes;
- la reconciliación final no es exacta.

El comando es:

```bash
php artisan prodex:inventory-reconcile
```

Sin `--apply` es auditoría y no modifica inventario.

Para inicializar el shadow stock únicamente después de revisar la auditoría:

```bash
php artisan prodex:inventory-reconcile --apply
```

## Máquina de transición por CD

`inventory_transition_states` controla cada Warehouse/CD por separado.

Modos:

### `legacy_only`

Estado inicial.

- Lecturas: `product_warehouse`.
- Escrituras: legacy.
- Nuevo stock: no participa en ejecución productiva.

### `shadow_compare`

Solo se permite después de reconciliación exacta.

- Lecturas productivas: legacy.
- Escrituras productivas: legacy.
- Shadow stock: se utiliza para comparación/auditoría.
- No se sincroniza automáticamente después de cada escritura.

Sirve para medir todas las rutas legacy que todavía necesitan migración.

### `dual_write`

Solo se permite después de reconciliación exacta.

- Lecturas productivas: legacy.
- Escritura funcional: legacy sigue siendo primaria.
- Las rutas que ya hayan sido migradas llaman explícitamente `InventoryCompatibilityService::mirrorLegacySnapshot()` después de mutar `product_warehouse`.
- El shadow se fija al resultado agregado del legacy, en lugar de repetir la aritmética histórica.

Esto permite migrar controladores uno por uno sin asumir que todos utilizan el mismo patrón de incremento/decremento.

### `location_primary`

Reservado para una fase posterior. No existe comando público para activarlo todavía.

Antes de permitirlo deberán estar migradas todas las escrituras del dominio correspondiente y existir monitoreo de reconciliación.

## `InventoryCompatibilityService`

Responsabilidades:

- conocer el modo del CD;
- auditar reconciliación;
- activar `shadow_compare` o `dual_write` únicamente si el estado es exacto;
- mantener legacy como lectura durante transición;
- sincronizar snapshots legacy hacia el motor nuevo en rutas migradas;
- marcar inmediatamente un CD como `mismatch` si detecta una divergencia.

Una divergencia bloquea nuevas operaciones de dual-write hasta revisión.

## `InventoryReadService`

Capa de lectura preparada para migrar controladores sin cambiar resultados actuales.

Mientras el modo sea:

- `legacy_only`;
- `shadow_compare`;
- `dual_write`;

aún lee `product_warehouse`.

Solo un futuro `location_primary` saludable lee `inventory_location_stocks`.

También soporta consultas batch para evitar N+1 al listar productos.

## Comando de transición

```bash
php artisan prodex:inventory-transition --mode=audit
```

Opciones de modo permitidas en esta etapa:

```text
audit
legacy_only
shadow_compare
dual_write
```

Puede limitarse por tenant y CD:

```bash
php artisan prodex:inventory-transition \
  --tenants=<TENANT_ID> \
  --warehouse=<WAREHOUSE_ID> \
  --mode=shadow_compare
```

`location_primary` no se permite desde este comando.

## Orden de migración de dominios

1. Lecturas de inventario.
2. Movimientos internos controlados.
3. Compras y recepciones.
4. POS y ventas.
5. Devoluciones, ajustes y daños.
6. Transferencias logísticas.
7. Ecommerce e integraciones externas.
8. Dashboard y reportes.

Cada dominio debe pasar por:

```text
legacy only
→ backfill exacto
→ shadow compare
→ dual write
→ reconciliación sostenida
→ location primary (fase futura)
```

## Regla de despliegue

Crear las tablas de esta fase NO autoriza automáticamente dual-write ni cambia la fuente productiva.

Después de `prodex:tenant-upgrade`, todos los CDs permanecen efectivamente en `legacy_only` hasta una acción administrativa explícita.

## Principio de seguridad

Durante toda esta etapa:

> Si existe duda, PRODEX lee legacy.

El motor nuevo nunca debe convertirse silenciosamente en fuente primaria por el simple hecho de que sus tablas existan.
