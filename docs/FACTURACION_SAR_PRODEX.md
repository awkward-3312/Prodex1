# Manual de Facturación SAR en PRODEX

Este instructivo describe cómo preparar, emitir, reimprimir y anular facturas fiscales desde PRODEX para un tenant configurado en Honduras. La configuración de PRODEX no sustituye una autorización del SAR: los datos de CAI, rangos, fechas y demás información fiscal deben corresponder a la documentación autorizada al obligado tributario.

## 1. Principio de funcionamiento

PRODEX separa la **configuración vigente** de la **información histórica de cada factura**.

Los datos que el tenant puede cambiar —razón social, nombre comercial, dirección, teléfono, correo, textos de la factura, clasificación fiscal de productos, datos de clientes, puntos de emisión y autorizaciones— se utilizan para facturas futuras. Cuando una factura SAR se emite, PRODEX guarda una copia o *snapshot* del emisor, cliente, líneas, impuestos, totales y configuración de presentación. Por esa razón, editar la configuración después no reescribe una factura ya emitida.

Los datos generados por una factura emitida, como número fiscal, CAI utilizado, rango, correlativo, fecha de emisión, líneas vendidas, importes e impuestos, no deben modificarse manualmente.

## 2. Acceso

La configuración se encuentra en **Contabilidad > Facturación SAR**. El usuario necesita permisos de configuración del sistema para modificarla.

La pantalla reúne cinco áreas: perfil fiscal, contenido de la factura, clasificación fiscal de productos, datos fiscales de clientes, puntos de emisión y autorizaciones/rangos.

## 3. Perfil fiscal

Complete los datos exactamente como correspondan al negocio:

- **RTN**: RTN del obligado tributario.
- **Razón social**: nombre legal.
- **Nombre comercial**: nombre que utiliza el establecimiento, si aplica.
- **Dirección de casa matriz**.
- **Teléfono**.
- **Correo electrónico**.
- **Facturación fiscal habilitada**: activa la emisión SAR. PRODEX no permite habilitarla si no existe una autorización activa.

Los cambios se aplican a nuevas facturas. Las facturas anteriores conservan los datos que tenían al emitirse.

## 4. Contenido y presentación de la factura

El tenant puede modificar los siguientes datos para facturas futuras:

- Título del documento, por ejemplo `FACTURA`.
- Etiqueta del tipo de venta, por ejemplo `CONTADO`.
- Sitio web.
- Mensaje al pie.
- Texto para el original entregado al cliente.
- Texto para la copia del obligado tributario emisor.
- Mostrar u ocultar logo.
- Mostrar u ocultar referencia interna.
- Mostrar u ocultar cajero.
- Mostrar u ocultar almacén.
- Mostrar u ocultar resumen de pago.
- Mostrar u ocultar dirección del cliente.
- Mostrar u ocultar código del producto.
- Mostrar u ocultar total en letras.
- Mostrar u ocultar QR cuando el formato de impresión lo soporte.

Los campos fiscales obligatorios del documento no deben ocultarse mediante estas opciones. El número fiscal, CAI, rango autorizado, fecha límite y resumen tributario se toman del documento fiscal emitido.

## 5. Clasificación fiscal de productos

Antes de utilizar facturación SAR, clasifique los productos que se venden en el POS. PRODEX admite las siguientes categorías:

- **Gravado**: el producto genera ISV. Seleccione 15% o 18%, según corresponda.
- **Exento**: no genera ISV y se acumula en el importe exento.
- **Exonerado**: no genera ISV para la operación exonerada y se acumula en el importe exonerado.
- **Tasa cero**: utiliza tasa 0 y se presenta separadamente cuando existe importe de esta clase.

Para un producto gravado, indique además si el impuesto se maneja como **exclusivo** o **incluido en el precio**, según el esquema de precio que utilice el producto.

PRODEX conserva compatibilidad con productos anteriores que aún no se hayan clasificado. Sin embargo, para obtener correctamente el desglose 15%, 18%, exento y exonerado, todos los productos de un tenant con SAR habilitado deben ser revisados y clasificados.

## 6. Datos fiscales de clientes

En la misma pantalla se pueden mantener datos fiscales que después se copian a la factura cuando corresponda:

- RTN.
- Tipo de identificación.
- Número de identificación.
- Número de registro SAG/SAR, cuando aplique.
- Número de registro exonerado, cuando aplique.

Para operaciones de L 10,000 o más, PRODEX exige que el cliente tenga RTN o un documento de identificación registrado antes de emitir la factura fiscal.

## 7. Datos de exoneración de una venta

Algunas referencias pertenecen a una operación concreta y no deben asumirse como permanentes del cliente. Cuando la venta lo requiera, deben capturarse para esa operación:

- Número de orden de compra exenta.
- Número de registro SAG/SAR usado en la operación.
- Número de registro exonerado.
- Número de carnet o documento de exoneración, cuando corresponda.

Estos datos se congelan dentro del documento fiscal emitido.

## 8. Puntos de emisión

Cada punto de emisión tiene:

- Código de establecimiento de tres dígitos.
- Código de punto de emisión de tres dígitos.
- Nombre descriptivo.
- Dirección.
- Almacén relacionado.
- Caja física relacionada, cuando corresponda.
- Estado activo/inactivo.

Una caja debe corresponder al mismo almacén del punto de emisión. PRODEX utiliza esta relación para determinar qué autorización debe consumir una venta POS.

## 9. Autorizaciones, CAI y rangos

Para cada autorización registre:

- Punto de emisión.
- Tipo de documento; para factura normalmente se utiliza el código configurado para factura.
- CAI.
- Inicio del rango.
- Final del rango.
- Siguiente correlativo.
- Fecha de autorización, cuando se tenga disponible.
- Fecha límite de emisión.

La autorización se crea primero como **Borrador**. Revise los datos y luego actívela. Al activar una autorización para el mismo punto y tipo de documento, PRODEX deshabilita la autorización activa anterior.

PRODEX impide activar una autorización vencida, con correlativo fuera del rango o asociada a un punto inactivo. También impide registrar rangos superpuestos para el mismo punto y tipo de documento.

## 10. Numeración fiscal

Al emitir una factura, PRODEX arma el número fiscal con la estructura:

`EEE-PPP-TT-NNNNNNNN`

Donde:

- `EEE`: código de establecimiento.
- `PPP`: código del punto de emisión.
- `TT`: tipo de documento.
- `NNNNNNNN`: correlativo de ocho dígitos.

El correlativo se incrementa dentro de la misma operación de base de datos que crea el documento fiscal. Si el rango se agota, la autorización cambia a estado agotado.

## 11. Cálculo fiscal del POS

Para Honduras, PRODEX permite trabajar con impuestos por línea. Cada producto puede contribuir a una de las siguientes bolsas fiscales:

- Importe exonerado.
- Importe exento.
- Importe tasa cero.
- Importe gravado 15%.
- ISV 15%.
- Importe gravado 18%.
- ISV 18%.
- Otras tasas, si en el futuro se habilitan para otra jurisdicción.

Los descuentos manuales, descuentos por puntos y promociones se aplican antes del resumen fiscal y se distribuyen para que el documento pueda reconciliar sus bases, impuestos y total.

El resumen fiscal se calcula una sola vez al emitir y se guarda en el documento. Las plantillas no deben volver a inventar o recalcular impuestos por separado.

## 12. Información de la factura

Una factura fiscal de PRODEX puede contener, según configuración y según corresponda a la operación:

- Logo.
- Nombre comercial y razón social.
- RTN del emisor.
- Dirección de casa matriz.
- Dirección del punto de emisión.
- Teléfono, correo y sitio web.
- Denominación de factura y tipo de venta.
- Número fiscal.
- CAI.
- Rango autorizado.
- Fecha límite de emisión.
- Fecha y hora.
- Referencia interna.
- Almacén/punto de emisión.
- Cliente.
- RTN o identificación del cliente.
- Datos de exoneración cuando correspondan.
- Descripción de productos.
- Código de producto si está habilitado.
- Cantidad.
- Precio unitario.
- Descuento.
- Tasa aplicable.
- Importe por línea.
- Descuentos y rebajas.
- Subtotal.
- Importe exonerado.
- Importe exento.
- Importe tasa cero, cuando exista.
- Importe gravado 15%.
- Importe gravado 18%.
- ISV 15%.
- ISV 18%.
- Total.
- Total en letras, si está habilitado.
- Forma/resumen de pago según el formato disponible.
- Leyenda de original y copia.
- Mensaje al pie.
- QR cuando el formato lo soporte y esté habilitado.

## 13. Formatos de impresión

PRODEX conserva las opciones de impresión existentes:

- **Térmica**.
- **A4/PDF**.

En térmica se conservan los diseños de recibo existentes y los tamaños de papel configurados, incluyendo 58 mm, 80 mm y 88 mm. La información fiscal se agrega desde el mismo snapshot utilizado por A4 para evitar diferencias entre una impresión y otra.

El A4 utiliza la misma información fiscal congelada y presenta el desglose tributario de forma integrada; no debe aparecer una segunda factura o un bloque fiscal desconectado de la factura principal.

## 14. Emisión desde POS

Flujo recomendado:

1. Seleccione almacén/caja correcta.
2. Seleccione cliente.
3. Agregue los productos.
4. Verifique que la clasificación fiscal y precio de los productos sean correctos.
5. Si la operación es exenta/exonerada, complete los datos propios de esa venta.
6. Complete el pago.
7. Finalice la venta.
8. PRODEX valida punto de emisión y autorización.
9. PRODEX crea la venta, sus líneas y pagos.
10. PRODEX genera el documento SAR y toma el siguiente correlativo.
11. PRODEX congela emisor, cliente, configuración, líneas y resumen fiscal.
12. Se imprime o muestra el formato seleccionado.

Si la facturación SAR está habilitada y falta una autorización válida, la venta fiscal no debe continuar silenciosamente.

## 15. Reimpresión

Una reimpresión siempre debe utilizar el documento fiscal ya emitido. No debe generar un nuevo correlativo ni tomar la configuración fiscal actual para sustituir la información histórica.

Desde la lista de ventas se puede volver a abrir la factura o descargar el PDF. El número fiscal y demás snapshots permanecen iguales.

## 16. Anulación

Una factura fiscal emitida no se elimina como una venta normal. Debe utilizarse la opción de **Anular factura SAR** y registrar un motivo.

PRODEX conserva el documento y cambia su estado a anulado, junto con fecha, motivo y usuario que realizó la acción. Las reimpresiones deben identificar claramente que el documento está anulado.

## 17. Qué puede modificarse y qué no

### Configurable para facturas futuras

Perfil fiscal, nombre comercial, contactos, dirección vigente, textos de presentación, opciones visuales, clasificación fiscal de productos, datos fiscales de clientes, puntos de emisión y nuevas autorizaciones/rangos según corresponda.

### Congelado al emitir

Número fiscal, CAI usado, rango, correlativo, fecha/hora de emisión, emisor utilizado, cliente utilizado, información de exoneración usada, productos, cantidades, precios, descuentos, bases fiscales, impuestos, total y configuración de presentación que tenía la factura al emitirse.

## 18. Migraciones y despliegue

Los cambios de esta implementación incluyen estructura nueva en las bases de datos de los tenants. Después de actualizar el código deben ejecutarse las migraciones de tenants usando el procedimiento de despliegue de PRODEX.

Como también se modificó frontend Vue, debe compilarse el frontend antes de publicar los archivos generados. Luego se recomienda limpiar cachés de Laravel y reiniciar PHP-FPM según el flujo normal del servidor.

## 19. Comprobación antes de usar en producción

Antes de habilitar facturación SAR en un tenant real, realice pruebas con al menos estos escenarios:

1. Venta únicamente gravada 15%.
2. Venta únicamente gravada 18%.
3. Venta únicamente exenta.
4. Venta únicamente exonerada.
5. Venta mixta 15% + 18% + exenta.
6. Venta con descuento.
7. Venta con promoción.
8. Venta con puntos.
9. Venta mayor o igual a L 10,000 con identificación del cliente.
10. Venta exonerada con referencias de la operación.
11. Impresión térmica en el tamaño de papel usado por el tenant.
12. A4/PDF.
13. Reimpresión desde Ventas.
14. Anulación y reimpresión del documento anulado.
15. Agotamiento y vencimiento de una autorización de prueba.

Los importes de la factura deben reconciliar siempre con el total cobrado y con el documento fiscal almacenado.

## 20. Nota de cumplimiento

PRODEX proporciona controles y estructura técnica para administrar la facturación configurada por el tenant. El tenant es responsable de cargar datos que correspondan a sus autorizaciones y a su situación fiscal. Ante cambios normativos o dudas sobre el tratamiento de una operación específica, debe confirmarse el criterio aplicable con el SAR o con el profesional fiscal/contable del negocio antes de cambiar la configuración de producción.
