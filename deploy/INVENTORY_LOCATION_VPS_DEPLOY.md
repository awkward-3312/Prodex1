# PRODEX — despliegue seguro de sucursales, ubicaciones e inventario por ubicación

Este procedimiento corresponde al bloque que introduce:

- sucursales separadas de almacenes/CD;
- ubicaciones de inventario (piso de venta, bodega, cuarentena, etc.);
- usuarios con alcance por sucursal/ubicación;
- cajas ligadas a sucursal + ubicación;
- POS por ubicación;
- transferencias entre ubicaciones con QR;
- lotes y seriales/IMEI por ubicación;
- recepción, faltantes, defectuosos y cuarentena;
- compatibilidad temporal con `product_warehouse`.

## Principios del despliegue

1. No eliminar ni renombrar tablas legacy durante este rollout.
2. `product_warehouse` continúa siendo compatible durante la transición.
3. Primero se aplica esquema, luego se audita y reconcilia inventario.
4. Nunca ejecutar `--apply` si la auditoría reporta cantidades legacy negativas.
5. No activar `dual_write` hasta que el CD esté 100% reconciliado.
6. Probar primero un tenant de prueba antes de extender la transición operativa a todos.

---

## 1. Respaldo del VPS

```bash
cd /var/www/prodex

sudo mkdir -p /var/backups/prodex

sudo mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  --all-databases \
  | gzip > /var/backups/prodex/pre-inventory-location-$(date +%Y%m%d-%H%M%S).sql.gz

ls -lh /var/backups/prodex | tail
```

No continuar si el archivo de respaldo no existe o tiene tamaño anormalmente pequeño.

---

## 2. Actualizar código en el VPS

```bash
cd /var/www/prodex

git status --short
git pull
git log -1 --oneline
```

`git status --short` debe estar vacío antes del `git pull` o los cambios locales deben revisarse antes de continuar.

---

## 3. Dependencias PHP

Si `composer.lock` cambió desde el último despliegue:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

Si no cambió, este paso puede omitirse.

---

## 4. Aplicar migraciones controladas a todos los tenants

```bash
php artisan prodex:tenant-upgrade
```

Resultado esperado:

```text
Summary: N tenants, N healthy, 0 warnings, 0 failures.
```

No continuar con el backfill si existen `warnings` o `failures`.

---

## 5. Publicar/actualizar Manual PRODEX

```bash
php artisan db:seed \
  --class="Database\\Seeders\\Central\\ProdexManualsSeeder" \
  --force
```

El seeder es idempotente.

---

## 6. Auditoría inicial del inventario

Este comando NO modifica existencias:

```bash
php artisan prodex:inventory-reconcile
```

Antes del primer backfill es normal que los CD aparezcan como `PENDIENTE` porque `product_warehouse` ya tiene cantidades y la nueva ubicación principal todavía está vacía.

DETENER el despliegue si aparece cualquiera de estos casos:

- cantidades legacy negativas;
- una ubicación nueva ya contiene stock diferente al legado;
- error de esquema;
- excepción de reconciliación.

---

## 7. Backfill controlado

Solo después de revisar la auditoría anterior:

```bash
php artisan prodex:inventory-reconcile --apply
```

La operación se ejecuta por transacciones y se niega a sobrescribir una ubicación que ya tenga stock divergente.

Después ejecutar de nuevo:

```bash
php artisan prodex:inventory-reconcile
```

Resultado esperado:

```text
Summary: N tenants, M warehouses/CD, M reconciled, 0 pending differences, 0 failures.
```

No continuar si queda alguna diferencia.

---

## 8. Activar comparación de sombra

Una vez reconciliado:

```bash
php artisan prodex:inventory-transition --mode=shadow_compare
```

Y comprobar:

```bash
php artisan prodex:inventory-transition --mode=audit
```

Todos los CD deben mostrar `status=healthy` y `diferencias=0`.

`shadow_compare` no cambia la fuente productiva legacy.

---

## 9. Pruebas PHP focalizadas en el VPS

```bash
vendor/bin/phpunit \
  tests/Unit/InventoryLocationServiceTest.php \
  tests/Unit/InventoryServiceTest.php \
  tests/Unit/LegacyInventoryReconciliationServiceTest.php \
  tests/Unit/InventoryCompatibilityServiceTest.php \
  tests/Unit/InventoryReadServiceTest.php \
  tests/Unit/BatchLocationServiceTest.php \
  tests/Unit/SerialLocationServiceTest.php \
  tests/Unit/InternalInventoryMoveServiceTest.php

vendor/bin/phpunit \
  tests/Unit/PosLocationStockBridgeTest.php \
  tests/Unit/PosLocationSaleStockServiceTest.php

vendor/bin/phpunit \
  tests/Unit/TransferSerialLocationServiceTest.php \
  tests/Unit/TransferBatchIssueServiceTest.php \
  tests/Unit/TransferLogisticsServiceTest.php \
  tests/Unit/TransferLogisticsBatchUnitsTest.php \
  tests/Unit/TransferDispatchPreflightTest.php
```

Cualquier fallo debe resolverse antes de activar `dual_write`.

---

## 10. Frontend

Por el historial de memoria del VPS, el build recomendado se realiza en la Mac y se sincronizan los assets compilados.

En la Mac:

```bash
cd ~/Documents/GitHub/Prodex1

git pull

NODE_OPTIONS="--max-old-space-size=8192" npm run production
```

Luego:

```bash
rsync -avz --delete public/js/ \
  prodexadmin@2.24.77.45:/var/www/prodex/public/js/

rsync -avz --delete public/css/ \
  prodexadmin@2.24.77.45:/var/www/prodex/public/css/

rsync -avz public/mix-manifest.json \
  prodexadmin@2.24.77.45:/var/www/prodex/public/mix-manifest.json
```

En el VPS:

```bash
cd /var/www/prodex

sudo chown -R prodexadmin:www-data public/js public/css public/mix-manifest.json
sudo chmod -R ug+rwX public/js public/css public/mix-manifest.json
```

---

## 11. Limpiar caché y reiniciar PHP

```bash
cd /var/www/prodex

php artisan optimize:clear
sudo systemctl restart php8.3-fpm
sudo systemctl is-active php8.3-fpm
```

Debe responder:

```text
active
```

Si hay workers persistentes de cola administrados por Supervisor, reiniciarlos después del código nuevo:

```bash
sudo supervisorctl status
```

Si aparece un worker PRODEX administrado por Supervisor:

```bash
sudo supervisorctl restart prodex-queue-worker:*
```

No ejecutar ese restart si el VPS no utiliza esa configuración.

---

## 12. Smoke test en tenant de prueba

Antes de activar `dual_write` globalmente:

1. iniciar sesión normalmente y confirmar Dashboard;
2. abrir Sucursales;
3. crear/revisar una sucursal;
4. comprobar Piso de venta y Bodega de sucursal;
5. revisar Usuarios y accesos;
6. asignar un usuario a la sucursal y al Piso de venta;
7. revisar Caja física ligada a Sucursal + Piso de venta;
8. abrir POS y confirmar que muestra la sucursal/ubicación correcta;
9. realizar una venta pequeña y verificar que disminuye el Piso de venta correcto;
10. devolver esa venta y verificar que vuelve a la misma ubicación;
11. hacer movimiento interno Bodega → Piso;
12. crear transferencia CD/Bodega → ubicación destino;
13. aprobar/despachar;
14. comprobar notificación del receptor;
15. escanear/abrir QR;
16. recibir cantidades correctas;
17. probar faltante;
18. probar defectuoso y cuarentena;
19. resolver una incidencia;
20. si existe producto por lote, comprobar identidad del lote;
21. si existe producto serializado/IMEI, comprobar serial origen → tránsito → destino.

Después del smoke test:

```bash
php artisan prodex:inventory-transition --mode=audit
php artisan prodex:inventory-reconcile
```

No debe aparecer divergencia inesperada.

---

## 13. Activar dual-write

Solo después de un smoke test exitoso.

Primero se recomienda el tenant de prueba:

```bash
php artisan prodex:inventory-transition \
  --tenants=<TENANT_ID_DE_PRUEBA> \
  --mode=dual_write
```

Repetir una venta/compra/movimiento pequeño y luego:

```bash
php artisan prodex:inventory-transition \
  --tenants=<TENANT_ID_DE_PRUEBA> \
  --mode=audit
```

Debe seguir `healthy`, sin diferencias.

Cuando el tenant de prueba permanezca sano, activar el resto:

```bash
php artisan prodex:inventory-transition --mode=dual_write
```

Y volver a auditar:

```bash
php artisan prodex:inventory-transition --mode=audit
```

IMPORTANTE: incluso en `dual_write`, `product_warehouse` continúa siendo compatible durante esta etapa. No usar ni implementar `location_primary` todavía.

---

## 14. Rollback operativo

Si se detecta un problema antes de haber creado operaciones nuevas por ubicación:

```bash
php artisan prodex:inventory-transition --mode=legacy_only
php artisan optimize:clear
sudo systemctl restart php8.3-fpm
```

Esto devuelve el modo de transición a legacy; no borra tablas ni historial.

Si ya se registraron ventas, transferencias o movimientos reales en ubicaciones nuevas, NO restaurar la base de datos ni borrar tablas sin reconciliar primero los movimientos realizados durante la ventana de despliegue.

---

## Resultado final esperado

- Todos los tenants: esquema `healthy`.
- Todos los CD: reconciliación exacta.
- `shadow_compare` antes de pruebas funcionales.
- `dual_write` solo después del smoke test.
- `location_primary` permanece deshabilitado.
- POS nuevo: Sucursal → Piso de venta → Caja.
- Transferencias: InventoryLocation → InventoryLocation.
- Stock correcto, defectuoso, faltante, lote y serial/IMEI conservan trazabilidad física.
