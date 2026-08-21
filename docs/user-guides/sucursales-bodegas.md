# Sucursales y bodegas en PRODEX

## Para qué sirve

Una **sucursal** representa una ubicación o unidad operativa de la empresa. Una **bodega (warehouse)** representa un lugar donde PRODEX controla existencias. Una sucursal puede tener una o varias bodegas.

Ejemplo:

- Sucursal Mall
  - Bodega de venta
  - Bodega trasera
  - Bodega de cuarentena
- Centro de distribución
  - Bodega principal

## Orden recomendado de configuración

1. Cree primero la sucursal.
2. Complete su código, ubicación y responsable.
3. Cree o asigne las bodegas que pertenecen a esa sucursal.
4. Defina una bodega predeterminada si la sucursal tiene más de una.
5. En Gestión de personal, asigne cada empleado a su sucursal.
6. En Usuarios y accesos, configure el rol y las bodegas que ese usuario puede operar.

## Regla importante

El **rol** define qué puede hacer una persona. La **sucursal/bodega asignada** define dónde puede hacerlo. Tener permiso de inventario no concede acceso automático al inventario de todas las sucursales.

## Bodegas y stock

Cada bodega mantiene su propio inventario. Mover existencias entre bodegas debe realizarse mediante una transferencia de stock; no edite cantidades manualmente para simular un traslado.

## Recomendación

No elimine una sucursal o bodega que ya tenga historial. Desactívela para conservar trazabilidad de ventas, movimientos, transferencias y auditorías.
