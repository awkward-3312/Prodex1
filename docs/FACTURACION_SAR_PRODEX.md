# Manual de Facturación SAR en PRODEX

Este instructivo describe cómo preparar, emitir, reimprimir y anular facturas fiscales desde PRODEX para un tenant configurado en Honduras. La configuración de PRODEX no sustituye una autorización del SAR: los datos de CAI, rangos, fechas y demás información fiscal deben corresponder a la documentación autorizada al obligado tributario.

## 1. Principio de funcionamiento

PRODEX separa la **configuración vigente** de la **información histórica de cada factura**.

Los datos que el tenant puede cambiar —razón social, nombre comercial, dirección, teléfono, correo, textos de la factura, clasificación fiscal de productos, datos de clientes, puntos de emisión y autorizaciones— se utilizan para facturas futuras. Cuando una factura SAR se emite, PRODEX guarda una copia o *snapshot* del emisor, cliente, líneas, impuestos, pagos, totales y configuración de presentación. Por esa razón, editar la configuración después no reescribe una factura ya emitida.

Los datos generados por una factura emitida, como número fiscal, CAI utilizado, rango, correlativo, fecha de emisión, líneas vendidas, importes e impuestos, no deben modificarse manualmente.

## 2. Acceso

La configuración se encuentra en **Contabilidad > Facturación SAR**. El usuario necesita permisos de configuración del sistema para modificarla.

La pantalla reúne: perfil fiscal, contenido de la factura, clasificación fiscal de productos, datos fiscales de clientes, puntos de emisión y autorizaciones/rangos.

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

El tenant puede modificar para facturas futuras:

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

El archivo del logo se administra desde **Ajustes del sistema > Configuración de apariencia > Cambiar logo**. Ese es el mismo logo que PRODEX utiliza en la factura fiscal cuando la opción **Mostrar logo** está habilitada. No es necesario cargar un segundo archivo en Facturación SAR ni en Recibo del POS.

Los campos fiscales obligatorios del documento no se eliminan con estas opciones. Número fiscal, CAI, rango autorizado, fecha límite y resumen tributario proceden del documento fiscal emitido.

## 5. Clasificación fiscal de productos

Antes de utilizar facturación SAR, revise la clasificación de todos los productos que se venden en el POS. PRODEX admite:

- **Gravado**: genera ISV. Seleccione 15% o 18%, según corresponda.
- **Exento**: no genera ISV y se acumula en el importe exento.
- **Exonerado**: no genera ISV para la operación exonerada y se acumula en el importe exonerado.
- **Tasa cero**: utiliza tasa 0 y se presenta separadamente cuando existe importe de esta clase.

Para un producto gravado indique además si el impuesto es **exclusivo** o **incluido en el precio**, según el esquema de precio del producto.

Durante la migración inicial de tenants de Honduras, los productos antiguos que dependían del 15% general del POS se preparan como gravados 15% para conservar el comportamiento histórico del total. **El tenant debe revisar esta clasificación y cambiar a Exento, Exonerado, 18% o Tasa cero los productos que realmente correspondan.**

Una vez SAR está habilitado, PRODEX no emite silenciosamente una factura con un producto sin clasificación fiscal válida. La venta se detiene e indica cuál producto debe configurarse.

Los productos nuevos también deben tener su clasificación revisada antes de utilizarlos en una factura SAR.

## 6. Datos fiscales de clientes

En la misma pantalla se pueden mantener datos fiscales que después se copian a la factura cuando correspondan:

- RTN.
- Tipo de identificación.
- Número de identificación.
- Número de registro SAG/SAR, cuando aplique.
- Número de registro exonerado, cuando aplique.

Para operaciones de L 10,000 o más, PRODEX exige que el cliente tenga RTN o un documento de identificación registrado antes de emitir la factura fiscal.

## 7. Datos fiscales propios de una venta

Algunas referencias pertenecen a una operación concreta y no deben asumirse como permanentes del cliente. En el POS aparece el botón **Datos fiscales** junto al área del cliente para registrar, cuando corresponda:

- Número de orden de compra exenta.
- Número de registro SAG/SAR utilizado en la operación.
- Número de registro exonerado.
- Número de carnet o documento de exoneración.

Si se guardaron datos, el botón indica **Fiscal configurado**. Se pueden volver a abrir, modificar antes de finalizar la venta o limpiar.

Al emitir, estos valores se guardan en la venta y se congelan dentro del documento fiscal. La siguiente venta comienza sin reutilizarlos automáticamente.

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
- Tipo de documento.
- CAI.
- Inicio del rango.
- Final del rango.
- Siguiente correlativo.
- Fecha de autorización, cuando esté disponible.
- Fecha límite de emisión.

La autorización se crea primero como **Borrador**. Revise los datos y luego actívela. Al activar otra autorización para el mismo punto y tipo de documento, PRODEX deshabilita la autorización activa anterior.

PRODEX impide activar una autorización vencida, con correlativo fuera del rango o asociada a un punto inactivo. También impide registrar rangos superpuestos para el mismo punto y tipo de documento.

## 10. Numeración fiscal

Al emitir una factura, PRODEX arma el número fiscal con la estructura:

`EEE-PPP-TT-NNNNNNNN`

Donde:

- `EEE`: código de establecimiento.
- `PPP`: código del punto de emisión.
- `TT`: tipo de documento.
- `NNNNNNNN`: correlativo de ocho dígitos.

El correlativo se incrementa dentro de la misma transacción de base de datos que crea el documento fiscal. Si el rango se agota, la autorización cambia a estado agotado. Una reimpresión nunca consume otro correlativo.

## 11. Cálculo fiscal del POS

Para Honduras, PRODEX utiliza impuestos por línea. El antiguo 15% general de la venta deja de agregarse una segunda vez sobre todo el carrito.

Cada producto contribuye a una de estas bolsas fiscales:

- Importe exonerado.
- Importe exento.
- Importe tasa cero.
- Importe gravado 15%.
- ISV 15%.
- Importe gravado 18%.
- ISV 18%.
- Otras tasas, si una jurisdicción futura las habilita.

Los descuentos manuales, descuentos por puntos y promociones se aplican antes del resumen fiscal y se distribuyen entre las líneas. El documento también conserva cualquier ajuste mínimo de redondeo necesario para reconciliar exactamente el total cobrado.

El resumen fiscal se calcula una vez al emitir y se guarda en el documento. Las plantillas A4, térmica, reimpresión e impresión directa leen esos mismos valores; no vuelven a inventar los impuestos.

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
- Cajero.
- Cliente.
- RTN o identificación del cliente.
- Datos de exoneración cuando correspondan.
- Descripción de productos.
- Código de producto si está habilitado.
- Cantidad.
- Precio unitario.
- Descuento.
- Clasificación/tasa aplicable.
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
- Ajuste de redondeo, únicamente cuando sea necesario.
- Envío, cuando exista.
- Total.
- Forma/resumen de pago y cambio.
- Total en letras, si está habilitado.
- Leyenda de original y copia.
- Mensaje al pie.
- QR cuando el formato lo soporte y esté habilitado.

## 13. Formatos de impresión

PRODEX conserva:

- **Térmica**.
- **A4/PDF**.
- **Impresión térmica directa por red (ESC/POS)** cuando está habilitada.

### Térmica

Los layouts 1–5 siguen disponibles como estilos visuales. Para una factura SAR, todos reciben el mismo contenido fiscal obligatorio y varían principalmente en densidad/espaciado. Se respetan los tamaños configurados de 58 mm, 80 mm y 88 mm.

Cuando la venta es fiscal, PRODEX construye un recibo fiscal completo a partir del snapshot y reemplaza el recibo genérico de la venta. Así se evita el problema anterior de mostrar un bloque SAR y debajo una segunda factura duplicada.

### A4/PDF

El A4 integra la información SAR dentro de una sola factura: emisor, cliente, detalle, impuestos, pagos y totales. No se imprime una segunda factura genérica debajo.

### Impresión directa por red

Para una venta SAR, la salida ESC/POS también se genera desde el mismo snapshot fiscal. Para una venta no SAR, PRODEX conserva el flujo de impresión de red anterior.

## 14. Emisión desde POS

Flujo recomendado:

1. Seleccione almacén/caja correcta.
2. Seleccione cliente.
3. Agregue los productos.
4. Verifique que la clasificación fiscal y precio de los productos sean correctos.
5. Si la operación requiere referencias fiscales específicas, abra **Datos fiscales** y complételas.
6. Complete el pago.
7. Finalice la venta.
8. PRODEX valida producto, cliente, punto de emisión y autorización.
9. PRODEX crea la venta, sus líneas y pagos dentro del flujo de la operación.
10. PRODEX genera el documento SAR y toma el siguiente correlativo.
11. PRODEX congela emisor, cliente, configuración, cajero, pagos, líneas y resumen fiscal.
12. Se imprime o muestra el formato seleccionado.

Si SAR está habilitado y falta una autorización válida, un producto no está clasificado o una venta de L 10,000 o más carece de identificación requerida, la emisión se detiene con un mensaje en lugar de generar una factura incompleta.

## 15. Reimpresión

Una reimpresión utiliza siempre el `SarFiscalDocument` ya emitido. No genera otro correlativo ni toma la configuración fiscal actual para sustituir información histórica.

Desde la lista de ventas se puede volver a abrir la factura o descargar el PDF. La reimpresión térmica utiliza el mismo renderer fiscal que el POS, y el número fiscal, CAI, rangos, datos del cliente, importes y configuración histórica permanecen iguales.

## 16. Anulación

Una factura fiscal emitida no debe eliminarse como si nunca hubiese existido. Utilice la opción de **Anular factura SAR** y registre el motivo.

PRODEX conserva el documento y cambia su estado a anulado, junto con fecha, motivo y usuario que realizó la acción. Las reimpresiones identifican el documento como **ANULADA** y conservan sus datos originales.

## 17. Qué puede modificarse y qué no

### Configurable para facturas futuras

Perfil fiscal, nombre comercial, contactos, dirección vigente, logo desde configuración general, textos de presentación, opciones visuales, clasificación fiscal de productos, datos fiscales de clientes, puntos de emisión y nuevas autorizaciones/rangos según corresponda.

### Configurable para la venta antes de emitir

Cliente seleccionado, referencias de exoneración/orden exenta de esa operación, productos, cantidades, descuentos, promociones, puntos y forma de pago, dentro de las reglas normales del POS.

### Congelado al emitir

Número fiscal, CAI usado, rango, correlativo, fecha/hora de emisión, emisor utilizado, cliente utilizado, información de exoneración usada, cajero, pagos, productos, cantidades, precios, descuentos, bases fiscales, impuestos, total y configuración de presentación que tenía la factura al emitirse.

## 18. Migraciones y despliegue

Esta implementación agrega estructura nueva en las bases de datos de los tenants y modifica frontend Vue.

Después de actualizar el código debe:

1. Ejecutar las **migraciones de tenants**.
2. Compilar el frontend.
3. Publicar los archivos compilados según el flujo habitual de PRODEX.
4. Limpiar cachés de Laravel.
5. Reiniciar PHP-FPM si corresponde al procedimiento de despliegue.

No requiere migración central para estas tablas fiscales.

## 19. Revisión inicial obligatoria después de migrar

Antes de habilitar SAR en un tenant real:

1. Abra **Contabilidad > Facturación SAR**.
2. Revise perfil fiscal.
3. Revise todos los puntos de emisión y cajas.
4. Revise CAI, rango, siguiente correlativo y fecha límite.
5. Revise la **clasificación fiscal de productos**. La migración conserva como 15% gravado el comportamiento histórico de los productos antiguos de Honduras, pero el tenant debe identificar cuáles realmente son exentos, exonerados, 18% o tasa cero.
6. Revise los clientes que usan RTN o exoneraciones.
7. Configure los textos y elementos visuales de factura.
8. Realice ventas de prueba antes de usar numeración real en producción.

## 20. Matriz mínima de pruebas

Antes de habilitar facturación SAR en producción, pruebe:

1. Venta únicamente gravada 15%.
2. Venta únicamente gravada 18%.
3. Venta únicamente exenta.
4. Venta únicamente exonerada.
5. Venta mixta 15% + 18% + exenta.
6. Venta mixta con exonerado.
7. Venta con descuento manual.
8. Venta con promoción.
9. Venta con puntos.
10. Venta con múltiples formas de pago.
11. Venta mayor o igual a L 10,000 con identificación del cliente.
12. Venta exonerada con referencias de la operación.
13. Ticket 58 mm.
14. Ticket 80 mm.
15. Ticket 88 mm, si el tenant lo utiliza.
16. Cada layout térmico 1–5 que el tenant vaya a usar.
17. A4/PDF.
18. Reimpresión desde Ventas.
19. Impresión directa por red, si está habilitada.
20. Anulación y reimpresión del documento anulado.
21. Agotamiento y vencimiento de una autorización de prueba.
22. Cambio de nombre comercial/configuración después de emitir y comprobación de que la factura histórica no cambia.

Los importes deben reconciliar siempre con el total cobrado y con el documento fiscal almacenado.

## 21. Nota de cumplimiento

PRODEX proporciona controles y estructura técnica para administrar la facturación configurada por el tenant. El tenant es responsable de cargar datos que correspondan a sus autorizaciones y a su situación fiscal. Ante cambios normativos o dudas sobre el tratamiento de una operación específica, debe confirmarse el criterio aplicable con el SAR o con el profesional fiscal/contable del negocio antes de cambiar la configuración de producción.

## 22. Personalización visual del recibo POS

La apariencia de la factura térmica se administra en **Ajustes del sistema > Recibo del POS**. Esta configuración es por tenant y afecta la presentación, no el contenido fiscal que ya fue emitido.

La sección **Diseño de la factura / recibo** permite configurar:

- Ancho de papel: 58 mm, 80 mm u 88 mm.
- Tamaño del logo.
- Alineación de cabecera.
- Alineación de información fiscal.
- Alineación de datos del cliente.
- Alineación de productos.
- Alineación de totales.
- Alineación del pie de factura.
- Alineación del código QR.
- Tamaño de letra.
- Espaciado compacto, normal o amplio.
- Tipo de separadores.

La pantalla incluye una **Vista previa fiscal SAR (Honduras)** con datos de demostración. La vista previa solo sirve para comprobar la presentación; no consume numeración fiscal ni utiliza un CAI real.

El logo mostrado en las facturas se carga en **Ajustes del sistema > Configuración de apariencia > Cambiar logo**. En **Recibo del POS** se configura el tamaño y en **Facturación SAR** se controla si debe mostrarse. La factura real reutiliza ese mismo archivo, por lo que no deben mantenerse logos duplicados en distintas secciones.

Los ajustes visuales no pueden ocultar los elementos fiscales obligatorios de una factura SAR. El número fiscal, CAI, rango autorizado, fecha límite, RTN y resumen tributario continúan obteniéndose del documento fiscal almacenado.