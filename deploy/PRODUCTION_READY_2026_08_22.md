# PRODEX — checklist final de producción (2026-08-22)

Este documento congela el procedimiento de despliegue del bloque de:

- sucursales;
- CD/almacenes;
- ubicaciones de inventario;
- cajas por sucursal + ubicación;
- usuarios/roles/alcance;
- POS por ubicación;
- devoluciones por ubicación;
- movimientos internos;
- transferencias con QR;
- faltantes, defectuosos y cuarentena;
- lotes y seriales/IMEI por ubicación.

## Decisiones finales

- Transferencias de stock son capacidad operativa base de PRODEX para cualquier tenant con suscripción activa/trial. El plan sigue controlando otros módulos y límites, pero no impide trasladar inventario entre ubicaciones.
- `max_warehouses` limita CD/almacenes dedicados; una sucursal y sus ubicaciones internas no consumen un warehouse adicional.
- HRM no es requisito para crear usuarios, roles ni restricciones.
- `product_warehouse` se conserva durante la transición. No se elimina ni renombra en este despliegue.
- `location_primary` permanece deshabilitado. El rollout termina como máximo en `dual_write` después de validación.

## Commit mínimo esperado

Antes de desplegar, `git log -1 --oneline` debe mostrar este commit o uno posterior:

```text
ac92903544d3744282f4748dd8e8a8ed3b630c7a Validate core transfer capability wiring before rollout
```

## 1. Respaldo

```bash
cd /var/www/prodex
sudo mkdir -p /var/backups/prodex
sudo mysqldump --single-transaction --routines --triggers --all-databases \
  | gzip > /var/backups/prodex/pre-location-rollout-$(date +%Y%m%d-%H%M%S).sql.gz
ls -lh /var/backups/prodex | tail
```

DETENER si el respaldo no existe o tiene tamaño anormalmente pequeño.

## 2. Mac: actualizar y compilar

```bash
cd ~/Documents/GitHub/Prodex1
git status --short
git pull
git log -1 --oneline
NODE_OPTIONS="--max-old-space-size=8192" npm run production
```

No continuar si `git status --short` muestra cambios no revisados o si falla el build.

## 3. VPS: actualizar código

```bash
cd /var/www/prodex
git status --short
git pull
git log -1 --oneline
```

## 4. Migraciones controladas de tenants

```bash
php artisan prodex:tenant-upgrade
```

Resultado obligatorio:

```text
Summary: N tenants, N healthy, 0 warnings, 0 failures.
```

El comando incluye las migraciones de ubicaciones, POS, transferencias, seriales/IMEI y asignaciones de lotes/incidencias.

## 5. Manual PRODEX

```bash
php artisan db:seed --class="Database\\Seeders\\Central\\ProdexManualsSeeder" --force
```

## 6. Pruebas antes de modificar inventario

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
  tests/Unit/TransferLocationAccessControllerTest.php \
  tests/Unit/TransferDiscrepancyLocationAccessTest.php \
  tests/Unit/TransferLogisticsServiceTest.php \
  tests/Unit/TransferLogisticsBatchUnitsTest.php \
  tests/Unit/TransferDispatchPreflightTest.php
```

DETENER ante cualquier fallo.

## 7. Auditoría y backfill

Primero solo auditar:

```bash
php artisan prodex:inventory-reconcile
```

Si no hay negativos/divergencias peligrosas:

```bash
php artisan prodex:inventory-reconcile --apply
php artisan prodex:inventory-reconcile
```

No continuar hasta obtener reconciliación exacta.

## 8. Shadow compare

```bash
php artisan prodex:inventory-transition --mode=shadow_compare
php artisan prodex:inventory-transition --mode=audit
```

Todos los CD deben quedar `healthy` y con `diferencias=0`.

## 9. Publicar frontend

Desde Mac:

```bash
rsync -avz --delete public/js/ prodexadmin@2.24.77.45:/var/www/prodex/public/js/
rsync -avz --delete public/css/ prodexadmin@2.24.77.45:/var/www/prodex/public/css/
rsync -avz public/mix-manifest.json prodexadmin@2.24.77.45:/var/www/prodex/public/mix-manifest.json
```

En VPS:

```bash
cd /var/www/prodex
sudo chown -R prodexadmin:www-data public/js public/css public/mix-manifest.json
sudo chmod -R ug+rwX public/js public/css public/mix-manifest.json
php artisan optimize:clear
sudo systemctl restart php8.3-fpm
sudo systemctl is-active php8.3-fpm
```

Debe responder `active`.

Si existen workers persistentes:

```bash
sudo supervisorctl status
```

Reiniciar únicamente el grupo real de workers PRODEX si aparece configurado.

## 10. Smoke test obligatorio en tenant de prueba

Validar en este orden:

1. login -> Dashboard, sin 404/autorización incorrecta;
2. crear/revisar Sucursal;
3. confirmar Piso de venta y Bodega interna;
4. revisar CD/Almacén principal;
5. crear usuario sin HRM y asignar rol + sucursal + ubicación;
6. si HRM está disponible, vincular empleado opcionalmente;
7. caja física -> Sucursal + Piso de venta;
8. POS -> muestra contexto correcto;
9. venta -> descuenta Piso de venta, no CD;
10. devolución -> regresa a ubicación original;
11. movimiento interno Bodega -> Piso;
12. transferencia ubicación A -> ubicación B;
13. aprobación/despacho -> stock sale de origen y queda en tránsito;
14. receptor autorizado recibe notificación;
15. QR abre la transferencia correcta;
16. recepción correcta -> stock entra en destino;
17. faltante -> no entra a stock y crea incidencia;
18. defectuoso -> entra a cuarentena;
19. resolución de faltante recibido posteriormente;
20. liberación/baja/devolución a origen de defectuoso;
21. lote -> identidad y cantidad siguen la ubicación;
22. serial/IMEI -> origen -> tránsito -> destino/cuarentena/faltante;
23. usuario sin scope a la ubicación no puede ver/recibir/resolver la transferencia.

Después:

```bash
php artisan prodex:inventory-transition --mode=audit
php artisan prodex:inventory-reconcile
```

Ambos deben permanecer sin divergencias inesperadas.

## 11. Dual-write: solo después del smoke test

Primero el tenant de prueba:

```bash
php artisan prodex:inventory-transition --tenants=<TENANT_ID_PRUEBA> --mode=dual_write
php artisan prodex:inventory-transition --tenants=<TENANT_ID_PRUEBA> --mode=audit
```

Si permanece healthy, repetir operaciones pequeñas y auditar de nuevo.

Solo después extender a todos:

```bash
php artisan prodex:inventory-transition --mode=dual_write
php artisan prodex:inventory-transition --mode=audit
```

No activar `location_primary` en este rollout.

## 12. Rollback operativo

Si se detecta problema antes de operaciones nuevas irreconciliables:

```bash
php artisan prodex:inventory-transition --mode=legacy_only
php artisan optimize:clear
sudo systemctl restart php8.3-fpm
```

No borrar tablas nuevas y no restaurar una base completa después de haber registrado operaciones reales por ubicación sin reconciliar primero la ventana de cambios.
