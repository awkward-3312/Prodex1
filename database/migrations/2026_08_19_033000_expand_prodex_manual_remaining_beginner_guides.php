<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasTable('kb_articles')) return;
        $db = DB::connection('central');
        $now = now();
        $guides = [
'prodex-realizar-venta-pos' => <<<'HTML'
<div class="manual-intro"><strong>Esta guía explica una venta desde cero.</strong> Imagina que un cliente llega con una botella de agua, paga en efectivo y necesita su comprobante. Veremos qué debe hacer el cajero y qué debe revisar antes de confirmar.</div>
<h2>Antes de entrar al POS</h2><p>El POS es la pantalla de caja donde agregas lo que compra el cliente y registras cómo paga. Para que funcione correctamente necesitas usuario autorizado, almacén, caja abierta y productos configurados.</p>
<ul class="manual-checklist"><li>Estoy usando mi propio usuario.</li><li>Estoy trabajando en el almacén correcto.</li><li>Mi caja física está asignada y abierta.</li><li>El producto tiene precio y existencia.</li></ul>
<h2>Paso 1. Entra al Punto de Venta</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Abre POS.</strong><br>Antes de tocar productos, mira qué almacén y caja estás utilizando. Esto evita descontar inventario o registrar efectivo en el lugar equivocado.</div>
<h2>Paso 2. Elige al cliente</h2><p>El cliente le dice a PRODEX quién está comprando. Para una venta común al público puede utilizarse el cliente general configurado. Si la persona necesita que sus datos aparezcan en el documento, selecciónala o créala correctamente antes de cobrar.</p>
<h2>Paso 3. Agrega los productos</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Busca por nombre, código o escáner.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Comprueba el producto.</strong><br>No te fijes solo en una palabra del nombre. Revisa presentación o variante.</div><div class="manual-step"><span class="manual-step-number">3</span><strong>Comprueba la cantidad.</strong><br>Si el cliente lleva 3, la línea debe indicar 3.</div>
<h2>Paso 4. Lee el resumen antes de cobrar</h2><p>Revisa productos, cantidades, precios, descuentos, impuestos y total. Este es el momento más fácil para corregir un error.</p><div class="manual-note"><strong>Ejemplo:</strong> si el cliente lleva 2 aguas a L 15 cada una, comprueba que la cantidad sea 2 y que el total corresponda antes de recibir el dinero.</div>
<h2>Paso 5. Cobra</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Presiona Pagar ahora.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona el método que realmente utilizó el cliente.</strong><br>No marques efectivo si pagó por otro medio.</div><div class="manual-step"><span class="manual-step-number">3</span><strong>Registra el monto recibido.</strong><br>Si el sistema muestra cambio, compáralo antes de entregar dinero.</div><div class="manual-step"><span class="manual-step-number">4</span><strong>Confirma una sola vez.</strong><br>Espera la respuesta del sistema; evita repetir el cobro por impaciencia.</div>
<h2>Paso 6. Entrega el comprobante</h2><p>Cuando PRODEX confirme la operación, revisa el documento y entrégalo o imprímelo según el proceso de tu negocio. Si es factura SAR, verifica especialmente numeración y datos fiscales.</p>
<h2>Si algo sale mal</h2><details class="manual-details"><summary>El producto no aparece</summary><p>Revisa nombre/código, estado del producto y almacén. No crees otro producto sin comprobar primero si ya existe.</p></details><details class="manual-details"><summary>No puedo vender porque la caja no está disponible</summary><p>Comprueba que tienes una caja asignada, que está abierta y que no está siendo utilizada por otro usuario.</p></details><details class="manual-details"><summary>Me equivoqué después de finalizar</summary><p>No borres ni inventes movimientos para compensar. Localiza la venta y utiliza el flujo de devolución o corrección que corresponda.</p></details>
<div class="manual-success"><strong>Venta terminada correctamente:</strong> el cliente y los productos son correctos, el pago coincide con lo recibido, la venta quedó registrada y el comprobante corresponde a esa operación.</div>
HTML,
'prodex-revisar-venta-antes-cobrar' => <<<'HTML'
<div class="manual-intro"><strong>Haz esta revisión antes de presionar el botón final de cobro.</strong> Son pocos segundos que pueden evitar una devolución, diferencia de caja o factura incorrecta.</div>
<h2>Revisa de arriba hacia abajo</h2><table><thead><tr><th>Qué revisar</th><th>Pregunta sencilla</th></tr></thead><tbody><tr><td>Cliente</td><td>¿Esta venta pertenece a esta persona/empresa?</td></tr><tr><td>Producto</td><td>¿Es exactamente el artículo que se está llevando?</td></tr><tr><td>Cantidad</td><td>¿El número en pantalla coincide con las unidades físicas?</td></tr><tr><td>Precio</td><td>¿Es el precio que corresponde?</td></tr><tr><td>Descuento</td><td>¿Está autorizado y bien aplicado?</td></tr><tr><td>Impuesto</td><td>¿El producto tiene el tratamiento configurado que corresponde?</td></tr><tr><td>Total</td><td>¿Es el monto que comunicaré al cliente?</td></tr><tr><td>Pago</td><td>¿Estoy registrando la forma en que realmente pagó?</td></tr></tbody></table>
<h2>Ejemplo de error fácil de detectar</h2><p>El cliente entrega dos productos, pero en pantalla aparece cantidad 3. Si lo detectas antes de cobrar, simplemente corriges la cantidad. Si finalizas primero, ya creaste una operación que tendrá que corregirse mediante el procedimiento adecuado.</p>
<div class="manual-warning"><strong>No tengas prisa con el último clic.</strong> Una venta debe confirmarse cuando pantalla, mercadería y dinero cuentan la misma historia.</div>
HTML,
'prodex-devolucion-venta' => <<<'HTML'
<div class="manual-intro"><strong>Una devolución no significa borrar una venta.</strong> Significa registrar que una parte o toda la mercadería vendida regresó y dejar evidencia de lo ocurrido.</div>
<h2>Ejemplo</h2><p>Ayer vendiste 3 camisetas y hoy el cliente devuelve 1. La venta original sigue existiendo; la devolución debe indicar que regresó únicamente 1.</p>
<h2>Paso 1. Encuentra la venta original</h2><p>Busca la operación y verifica referencia, fecha, cliente, productos y cantidades. Si eliges la venta equivocada, la devolución también quedará equivocada.</p>
<h2>Paso 2. Confirma qué regresa realmente</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Cuenta las unidades que el cliente entrega.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Identifica exactamente producto o variante.</strong></div><div class="manual-step"><span class="manual-step-number">3</span><strong>Abre la devolución desde el flujo correspondiente.</strong></div>
<h2>Paso 3. Registra solamente lo devuelto</h2><p>Si compró 3 y devuelve 1, registra 1; no devuelvas las 3 por accidente. Revisa importes antes de guardar.</p>
<h2>Paso 4. Comprueba el resultado</h2><p>Después de guardar, revisa la devolución, el efecto esperado en inventario y los saldos/documentos relacionados. Conserva la referencia de la venta original.</p>
<div class="manual-warning"><strong>No corrijas una devolución con un ajuste inventado.</strong> Si detectas un error, conserva la trazabilidad y utiliza el procedimiento correcto para corregir documentos.</div>
HTML,
'prodex-configurar-caja-fisica' => <<<'HTML'
<div class="manual-intro"><strong>Piensa en una caja física como la gaveta real donde trabaja un cajero.</strong> PRODEX necesita saber qué caja pertenece a qué almacén y quién puede usarla.</div>
<h2>Ejemplo</h2><p>Tu tienda tiene dos mostradores: Caja 1 y Caja 2. Ana trabaja en Caja 1. En PRODEX creas “Caja 1 - Principal”, la relacionas con la tienda y autorizas a Ana.</p>
<h2>Crear la caja</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Entra a Cajas físicas.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona crear una nueva caja.</strong></div><div class="manual-step"><span class="manual-step-number">3</span><strong>Escribe un nombre que cualquiera entienda.</strong><br>Evita nombres ambiguos como “Caja nueva”.</div><div class="manual-step"><span class="manual-step-number">4</span><strong>Selecciona el almacén.</strong><br>Es la ubicación donde opera esa caja.</div><div class="manual-step"><span class="manual-step-number">5</span><strong>Asigna los cajeros autorizados.</strong></div><div class="manual-step"><span class="manual-step-number">6</span><strong>Activa y guarda.</strong></div>
<h2>Después de crearla</h2><p>Inicia sesión con un usuario de prueba autorizado y comprueba que pueda seleccionar/usar la caja según el flujo de apertura. No compartas una misma sesión entre empleados.</p>
<div class="manual-warning"><strong>Caja creada no significa caja abierta.</strong> Crear la caja es configurar el punto de cobro; abrirla es comenzar un turno con un saldo inicial.</div>
HTML,
'prodex-abrir-cerrar-caja' => <<<'HTML'
<div class="manual-intro"><strong>Abrir caja = decir cuánto dinero hay al comenzar. Cerrar caja = contar cuánto hay al terminar y compararlo con lo que PRODEX esperaba.</strong></div>
<h2>Ejemplo completo</h2><p>Ana comienza con L 1,000 para dar cambio. Ese es su saldo inicial. Durante el turno registra ventas. Al final cuenta físicamente la gaveta y PRODEX compara ese conteo con los movimientos registrados.</p>
<h2>Apertura: antes de la primera venta</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Usa tu propio usuario.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona tu caja asignada.</strong></div><div class="manual-step"><span class="manual-step-number">3</span><strong>Cuenta el efectivo físicamente.</strong><br>No escribas lo que “debería” haber; escribe lo que realmente estás viendo.</div><div class="manual-step"><span class="manual-step-number">4</span><strong>Ingresa el saldo inicial y confirma.</strong></div>
<h2>Durante el turno</h2><p>Registra las ventas con su método de pago correcto. Si tu operación utiliza entradas o retiros de efectivo, regístralos mediante el flujo correspondiente. Sacar dinero sin registrarlo hará que el cierre parezca tener un faltante.</p>
<h2>Cierre: cuando terminaste de vender</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Finaliza operaciones pendientes.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Cuenta el dinero antes de retirarlo.</strong></div><div class="manual-step"><span class="manual-step-number">3</span><strong>Registra el conteo real.</strong></div><div class="manual-step"><span class="manual-step-number">4</span><strong>Compara esperado contra contado.</strong></div><div class="manual-step"><span class="manual-step-number">5</span><strong>Investiga cualquier diferencia.</strong><br>Revisa efectivo recibido, cambio entregado, entradas/retiros y ventas registradas.</div><div class="manual-step"><span class="manual-step-number">6</span><strong>Cierra el turno.</strong></div>
<div class="manual-warning"><strong>No cambies el conteo para obligar a que la diferencia sea cero.</strong> El conteo debe representar el dinero real. Una diferencia es información que debe investigarse.</div>
HTML,
'prodex-registrar-compra' => <<<'HTML'
<div class="manual-intro"><strong>Una compra le dice a PRODEX: “recibí esta mercadería de este proveedor para este almacén y me costó esto”.</strong> Si esos datos son incorrectos, también pueden quedar incorrectos inventario y costos.</div>
<h2>Ejemplo</h2><p>Tu proveedor entrega 10 cajas de un producto. La factura tiene una referencia y un costo. Antes de guardar, debes comprobar proveedor, almacén, producto, cantidad y costo contra lo recibido.</p>
<h2>Paso 1. Ten los documentos y mercadería a mano</h2><p>No registres de memoria. Usa la factura/orden del proveedor y, cuando corresponda, confirma físicamente lo recibido.</p>
<h2>Paso 2. Abre Agregar compra</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Selecciona fecha y proveedor.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Escribe una referencia útil.</strong><br>Debe ayudarte a encontrar el documento posteriormente.</div><div class="manual-step"><span class="manual-step-number">3</span><strong>Selecciona el almacén que recibe.</strong><br>Pregúntate: “¿en qué lugar físico quedará esta mercadería?”.</div>
<h2>Paso 3. Agrega los productos</h2><p>Busca cada artículo por nombre/código y verifica que sea exactamente el recibido. Luego registra cantidad y costo unitario. Revisa unidad de compra, descuento e impuesto cuando correspondan.</p>
<h2>Paso 4. Revisa los totales</h2><p>Compara subtotal, descuentos, impuestos, envío y total con la documentación que estás registrando. Una diferencia pequeña también merece revisión.</p>
<h2>Paso 5. Seriales o IMEI</h2><p>Si el producto se controla individualmente, registra cada serial/IMEI solicitado. Diez unidades físicas deben tener los identificadores correspondientes cuando ese control esté habilitado.</p>
<h2>Paso 6. Guarda y verifica</h2><p>Después de guardar, consulta la compra y el inventario del almacén. Confirma que ingresó la cantidad esperada.</p>
<div class="manual-warning"><strong>No uses una compra ficticia para aumentar stock.</strong> Si solo necesitas corregir una diferencia física, primero investiga y utiliza el flujo de inventario correspondiente.</div>
HTML,
'prodex-compra-no-coincide-recepcion' => <<<'HTML'
<div class="manual-intro"><strong>Si el papel dice una cosa y las cajas recibidas dicen otra, no adivines.</strong> Primero encuentra la diferencia.</div>
<h2>Ejemplo</h2><p>La factura dice 20 unidades, pero recibiste 18. Registrar 20 haría que PRODEX crea que tienes dos unidades que físicamente no existen.</p>
<h2>Revisa en este orden</h2><ol><li>Proveedor y referencia.</li><li>Almacén de recepción.</li><li>Producto y variante.</li><li>Unidad de compra.</li><li>Cantidad física.</li><li>Seriales/IMEI si aplican.</li><li>Costo, descuento, impuesto y envío.</li></ol>
<h2>¿Qué hago?</h2><p>Si todavía no guardaste, corrige los datos antes de finalizar. Si el documento ya fue registrado, utiliza el flujo de edición permitido cuando corresponda. Si la mercadería debe regresar al proveedor, registra una devolución de compra en lugar de esconder la diferencia con un ajuste.</p>
<div class="manual-warning"><strong>La meta no es que “cuadre la pantalla”; la meta es que PRODEX represente lo que realmente ocurrió.</strong></div>
HTML,
'prodex-sar-configuracion-inicial' => <<<'HTML'
<div class="manual-intro"><strong>Esta parte debe copiar información fiscal real, no inventarla.</strong> Facturación SAR conecta PRODEX con los datos que tu negocio necesita para emitir sus documentos fiscales en Honduras. Ten tu documentación autorizada a mano.</div>
<h2>Antes de comenzar: cuatro conceptos</h2><table><thead><tr><th>Concepto</th><th>Explicación sencilla</th></tr></thead><tbody><tr><td>RTN</td><td>Identificación tributaria del contribuyente.</td></tr><tr><td>Punto de emisión</td><td>Identifica desde dónde se emite la numeración fiscal.</td></tr><tr><td>CAI</td><td>Código de autorización asociado a la documentación fiscal autorizada.</td></tr><tr><td>Rango</td><td>Desde qué número hasta qué número estás autorizado a emitir, sujeto a sus fechas.</td></tr></tbody></table>
<h2>Paso 1. Entra a Contabilidad → Facturación SAR</h2><p>No busques este módulo entre productos o POS. Aquí se prepara la información fiscal que después utilizarán los documentos.</p>
<h2>Paso 2. Copia los datos del emisor</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>RTN:</strong> escríbelo exactamente.</div><div class="manual-step"><span class="manual-step-number">2</span><strong>Razón social y nombre comercial:</strong> no los intercambies.</div><div class="manual-step"><span class="manual-step-number">3</span><strong>Actividad, teléfono, correo y dirección fiscal:</strong> completa según la información válida de la empresa.</div>
<h2>Paso 3. Revisa parámetros tributarios</h2><p>Confirma las tasas y moneda que correspondan a tu configuración. No cambies una tasa solo para conseguir el total que esperabas; si tienes duda sobre el tratamiento fiscal de un producto, consúltalo con la persona responsable.</p>
<h2>Paso 4. Todavía no has terminado</h2><p>Guardar los datos de empresa no basta. Después debes configurar punto de emisión, CAI, rango autorizado y fechas. Sin esas piezas la numeración fiscal no está completa.</p>
<div class="manual-warning"><strong>En producción no hagas pruebas con CAI, RTN o rangos inventados.</strong> Utiliza información autorizada y verifica una factura antes de comenzar la operación normal.</div>
HTML,
'prodex-sar-punto-emision' => <<<'HTML'
<div class="manual-intro"><strong>El punto de emisión ayuda a PRODEX a saber qué numeración fiscal corresponde al lugar desde donde facturas.</strong></div>
<h2>Ejemplo mental</h2><p>Si tu negocio tiene un establecimiento y una caja autorizada para emitir documentos, el punto debe representar correctamente esa configuración. Los códigos no se inventan: se toman de la documentación que corresponda.</p>
<h2>Crear el punto</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Puntos de emisión dentro de Facturación SAR.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Copia código de establecimiento y código de punto.</strong></div><div class="manual-step"><span class="manual-step-number">3</span><strong>Escribe un nombre interno claro.</strong><br>El nombre te ayuda a reconocerlo; no sustituye los códigos fiscales.</div><div class="manual-step"><span class="manual-step-number">4</span><strong>Relaciona almacén y caja cuando corresponda.</strong><br>Así PRODEX puede vincular el lugar de la venta con el punto configurado.</div><div class="manual-step"><span class="manual-step-number">5</span><strong>Activa y guarda.</strong></div>
<h2>Comprueba</h2><ul class="manual-checklist"><li>Los códigos fueron copiados correctamente.</li><li>Elegiste el almacén correcto.</li><li>Elegiste la caja correcta cuando aplica.</li><li>El punto está activo.</li></ul><div class="manual-note">Si no aparece una caja o almacén para seleccionar, revisa primero que exista y esté correctamente configurado.</div>
HTML,
'prodex-sar-cai-rango-autorizado' => <<<'HTML'
<div class="manual-intro"><strong>Esta es una de las configuraciones que debes revisar con más cuidado.</strong> Copia CAI, numeración y fechas directamente de la autorización correspondiente.</div>
<h2>Ejemplo para entender el rango</h2><p>Si una autorización permite usar números desde A hasta B, PRODEX debe conocer exactamente ambos límites. No significa que puedas escoger cualquier numeración fuera de ellos.</p>
<h2>Ten a mano</h2><ul class="manual-checklist"><li>CAI.</li><li>Punto de emisión correspondiente.</li><li>Número inicial.</li><li>Número final.</li><li>Fecha de recepción cuando corresponda.</li><li>Fecha límite de emisión.</li></ul>
<h2>Registra sin reinterpretar</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Selecciona el punto correcto.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Copia el CAI.</strong><br>Revisa carácter por carácter.</div><div class="manual-step"><span class="manual-step-number">3</span><strong>Copia inicio y final.</strong><br>Conserva ceros y estructura.</div><div class="manual-step"><span class="manual-step-number">4</span><strong>Registra las fechas.</strong></div><div class="manual-step"><span class="manual-step-number">5</span><strong>Relee todo contra el documento y guarda.</strong></div>
<h2>Antes de facturar</h2><p>Comprueba que el rango esté activo, corresponda al punto correcto y no haya vencido. Después revisa visualmente un documento emitido según tu procedimiento de prueba.</p><div class="manual-warning"><strong>No “arregles” un CAI cambiando números hasta que el sistema lo acepte.</strong> Si un dato no coincide, vuelve a la documentación autorizada y encuentra el error.</div>
HTML,
'prodex-sar-revisar-factura-pos' => <<<'HTML'
<div class="manual-intro"><strong>No basta con que la factura se imprima.</strong> Antes de operar normalmente, comprueba que el documento muestre la información correcta.</div>
<h2>Revisión visual, de arriba hacia abajo</h2><h3>1. Emisor</h3><ul class="manual-checklist"><li>Razón social/nombre configurado correctamente.</li><li>RTN.</li><li>Dirección y datos fiscales que correspondan.</li></ul><h3>2. Autorización y numeración</h3><ul class="manual-checklist"><li>Número fiscal.</li><li>CAI.</li><li>Rango autorizado.</li><li>Fecha límite de emisión.</li></ul><h3>3. Cliente</h3><p>Si la operación requiere datos específicos del comprador, verifica que corresponda al cliente seleccionado y no a otra ficha.</p><h3>4. Detalle</h3><ul class="manual-checklist"><li>Productos.</li><li>Cantidades.</li><li>Precios.</li><li>Subtotal.</li><li>Impuestos.</li><li>Total.</li></ul>
<h2>Si encuentras un dato fiscal incorrecto</h2><p>Detén la emisión normal y corrige la configuración de origen antes de seguir. No intentes corregir manualmente cada impresión mientras la configuración sigue mal.</p><div class="manual-warning"><strong>Los datos fiscales deben validarse con la documentación y criterios aplicables a tu negocio.</strong></div>
HTML,
'prodex-crear-cliente' => <<<'HTML'
<div class="manual-intro"><strong>Un cliente es la ficha de la persona o empresa a quien vendes.</strong> Crear una ficha individual sirve para identificarla, conservar historial y usar sus datos cuando el proceso lo requiera.</div>
<h2>¿Siempre debo crear uno?</h2><p>No necesariamente. Para ventas rápidas al público puede existir un cliente general. Crea una ficha individual cuando necesites identificar al comprador, conservar contacto, manejar historial/saldo o emitir documentos con sus datos.</p>
<h2>Antes de crear: búscalo</h2><p>Escribe parte del nombre, teléfono o dato disponible. “Comercial López”, “Comercial Lopez” y “Comercial López S.A.” podrían terminar siendo la misma empresa si nadie revisa.</p>
<h2>Qué guardar</h2><table><thead><tr><th>Dato</th><th>Para qué sirve</th></tr></thead><tbody><tr><td>Nombre / razón social</td><td>Identificar correctamente al comprador.</td></tr><tr><td>Identificación fiscal</td><td>Usarla cuando el documento/proceso la requiera.</td></tr><tr><td>Teléfono / correo</td><td>Contacto y seguimiento.</td></tr><tr><td>Dirección</td><td>Referencia comercial/fiscal cuando aplique.</td></tr></tbody></table>
<h2>Después de guardar</h2><p>Vuelve a buscarlo y comprueba que existe una sola ficha con los datos correctos. En el POS selecciona esa ficha antes de cobrar cuando la venta deba quedar asociada a ese cliente.</p>
<div class="manual-warning"><strong>No inventes datos fiscales para llenar espacios.</strong> Si un dato es necesario para el documento, solicítalo y verifícalo según tu proceso.</div>
HTML,
'prodex-organizar-proveedores' => <<<'HTML'
<div class="manual-intro"><strong>Un proveedor es quien te vende mercadería o servicios.</strong> Su ficha permite que compras, pagos y devoluciones queden relacionadas con la misma empresa.</div>
<h2>Regla número uno: evita duplicados</h2><p>Antes de crear “Distribuidora ABC”, busca “ABC”, “Distribuidora” y cualquier nombre con el que ya pudiera estar registrada.</p>
<h2>Guarda información útil</h2><p>Nombre/razón social, identificación fiscal cuando corresponda, contacto, teléfono, correo y dirección. Usa una forma consistente de escribir nombres.</p>
<h2>Ejemplo</h2><p>Si todas las compras de ABC se registran en una sola ficha, podrás revisar su historial con claridad. Si creas tres fichas para la misma empresa, el historial queda dividido y parece que compraste a proveedores distintos.</p>
<h2>Al hacer una compra</h2><p>Detente un segundo antes de elegir el proveedor y confirma que seleccionaste la ficha correcta. No crees uno nuevo desde la prisa si ya existe.</p>
HTML,
'prodex-crear-usuario' => <<<'HTML'
<div class="manual-intro"><strong>Un usuario es la identidad de una persona dentro de PRODEX.</strong> Si Ana y Carlos trabajan en caja, Ana debe entrar como Ana y Carlos como Carlos. Así puedes saber quién hizo cada operación.</div>
<h2>Paso 1. Crea la identidad</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Gestión de usuarios.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Completa nombre, apellido y usuario.</strong></div><div class="manual-step"><span class="manual-step-number">3</span><strong>Agrega correo/teléfono cuando corresponda.</strong></div><div class="manual-step"><span class="manual-step-number">4</span><strong>Define una contraseña segura.</strong></div>
<h2>Paso 2. Dale un trabajo, no “todo”</h2><p>Selecciona el rol que corresponde a sus funciones. Un cajero necesita vender; eso no significa que necesite administrar usuarios, configuración o contabilidad.</p>
<h2>Paso 3. Define dónde puede trabajar</h2><p>Asigna únicamente los almacenes necesarios. Si será cajero, asigna también las cajas físicas que puede utilizar.</p>
<h2>Paso 4. Haz una prueba</h2><p>Guarda y prueba el acceso con ese usuario. Comprueba que puede hacer su trabajo y que no aparecen funciones que no necesita.</p>
<div class="manual-warning"><strong>Nunca soluciones un problema de permisos prestando la cuenta del administrador.</strong> Corrige el rol o la asignación del usuario.</div>
HTML,
'prodex-roles-permisos' => <<<'HTML'
<div class="manual-intro"><strong>Rol = tipo de trabajo. Permiso = acción que ese tipo de trabajo puede realizar.</strong> Piensa en las llaves de un edificio: cada empleado recibe las puertas que necesita, no todas las llaves.</div>
<h2>Ejemplo sencillo</h2><table><thead><tr><th>Rol</th><th>Necesita</th><th>Normalmente no necesita</th></tr></thead><tbody><tr><td>Cajero</td><td>POS, ventas necesarias, su almacén y caja</td><td>Administrar usuarios o cambiar configuración general</td></tr><tr><td>Inventario</td><td>Productos y movimientos autorizados</td><td>Acceso administrativo sin relación con su función</td></tr><tr><td>Supervisor</td><td>Funciones de revisión definidas por la empresa</td><td>Permisos que no formen parte de su responsabilidad</td></tr></tbody></table>
<h2>Cómo construir un rol</h2><ol><li>Escribe qué hace esa persona en la vida real.</li><li>Activa los permisos necesarios para esas tareas.</li><li>Revisa qué puede editar/eliminar.</li><li>Limita almacenes cuando corresponda.</li><li>Prueba con un usuario de ese rol.</li></ol>
<h2>Cuando alguien cambia de puesto</h2><p>No acumules permisos antiguos. Revisa su rol/asignaciones y deja solamente lo necesario para la nueva responsabilidad.</p>
<div class="manual-warning"><strong>“Administrador para que no tenga problemas” no es una buena solución.</strong> Da el acceso mínimo que permita realizar el trabajo.</div>
HTML,
'prodex-registrar-gastos' => <<<'HTML'
<div class="manual-intro"><strong>Un gasto es dinero que sale del negocio por una razón que quieres registrar y poder consultar después.</strong> Ejemplos pueden ser servicios, transporte u otros conceptos según la operación de tu empresa.</div>
<h2>Antes de registrar</h2><p>Ten el comprobante o referencia. Pregúntate: ¿qué pagué, cuándo, cuánto, por qué y a qué categoría pertenece?</p>
<h2>Paso a paso</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Gastos y crea un registro.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona la categoría correcta.</strong><br>Las categorías permiten saber después en qué se fue el dinero.</div><div class="manual-step"><span class="manual-step-number">3</span><strong>Indica fecha e importe.</strong></div><div class="manual-step"><span class="manual-step-number">4</span><strong>Selecciona cuenta/almacén si el formulario lo requiere.</strong></div><div class="manual-step"><span class="manual-step-number">5</span><strong>Escribe una nota o referencia entendible.</strong><br>“Factura energía agosto” explica más que “pago”.</div><div class="manual-step"><span class="manual-step-number">6</span><strong>Guarda y revisa el reporte.</strong></div>
<h2>Ejemplo de por qué importan las categorías</h2><p>Si registras electricidad, transporte y papelería como “Otros”, al final del mes sabrás cuánto gastaste, pero no en qué. Una clasificación consistente convierte el reporte en información útil.</p>
<div class="manual-warning"><strong>No dupliques un gasto porque no lo encontraste de inmediato.</strong> Busca por fecha, importe o referencia antes de volver a registrarlo.</div>
HTML,
'prodex-reportes-recomendados' => <<<'HTML'
<div class="manual-intro"><strong>Un reporte es una forma de hacerle una pregunta a los datos de PRODEX.</strong> No tienes que abrir todos. Elige el reporte según lo que quieres saber.</div>
<h2>Si preguntas “¿cuánto vendimos?”</h2><p>Consulta ventas y filtra el período correcto. Antes de comparar, confirma fechas, almacén y otros filtros.</p>
<h2>Si preguntas “¿qué se está acabando?”</h2><p>Revisa existencias/alertas de stock. Una alerta es útil solamente si los movimientos de inventario se registran correctamente.</p>
<h2>Si preguntas “¿qué compramos?”</h2><p>Revisa compras por período y proveedor. Esto ayuda a detectar concentración de compras y revisar costos.</p>
<h2>Si preguntas “¿en qué gastamos?”</h2><p>Revisa gastos por categoría. Si todo se registró como “Otros”, el reporte tendrá poco valor.</p>
<h2>Rutina sencilla</h2><table><thead><tr><th>Frecuencia</th><th>Qué conviene mirar</th></tr></thead><tbody><tr><td>Diario</td><td>Ventas, caja y alertas operativas.</td></tr><tr><td>Semanal</td><td>Productos vendidos, compras, movimientos/ajustes y gastos.</td></tr><tr><td>Mensual</td><td>Rentabilidad disponible, valoración de inventario y tendencias por producto/almacén.</td></tr></tbody></table>
<h2>Antes de tomar una decisión</h2><p>Mira los filtros. “Ventas de agosto” y “ventas de una sola sucursal en agosto” responden preguntas distintas. Compara períodos equivalentes y confirma que los documentos del período estén registrados.</p>
<div class="manual-note"><strong>PRODEX no puede reportar una operación que nunca se registró.</strong> La calidad del reporte depende de la calidad de los datos.</div>
HTML,
'prodex-crear-ticket-soporte' => <<<'HTML'
<div class="manual-intro"><strong>Un buen ticket permite entender el problema sin adivinar.</strong> En lugar de escribir “no funciona”, cuenta qué intentaste, dónde, qué esperabas y qué ocurrió.</div>
<h2>Antes de pedir ayuda</h2><ol><li>Actualiza la página.</li><li>Repite una vez el proceso con cuidado.</li><li>Anota el módulo.</li><li>Anota la referencia del documento si existe.</li><li>Toma una captura del mensaje completo sin mostrar contraseñas ni secretos.</li></ol>
<h2>Ejemplo de título</h2><p><strong>Bueno:</strong> “Caja 2 no permite apertura para usuario Ana”.<br><strong>Poco útil:</strong> “Urgente, no sirve”.</p>
<h2>Qué escribir</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Qué estabas haciendo.</strong><br>“Entré a Caja y seleccioné Caja 2”.</div><div class="manual-step"><span class="manual-step-number">2</span><strong>Qué esperabas.</strong><br>“Esperaba poder ingresar el saldo inicial”.</div><div class="manual-step"><span class="manual-step-number">3</span><strong>Qué ocurrió.</strong><br>Copia el mensaje visible o descríbelo exactamente.</div><div class="manual-step"><span class="manual-step-number">4</span><strong>Qué ya revisaste.</strong><br>Por ejemplo, asignación del usuario y estado de la caja.</div>
<h2>Nunca envíes</h2><ul><li>Contraseñas.</li><li>Claves privadas o secretos API.</li><li>Datos completos de tarjetas.</li><li>Información sensible que no sea necesaria.</li></ul><div class="manual-success"><strong>Después de enviar:</strong> continúa el mismo caso en el mismo ticket para que el historial no se divida.</div>
HTML,
'prodex-soluciones-rapidas' => <<<'HTML'
<div class="manual-intro"><strong>Cuando algo “no aparece”, no empieces cambiando configuraciones al azar.</strong> Sigue el árbol de revisión de la situación que se parece a tu problema.</div>
<h2>No veo una opción del menú</h2><ol><li>¿Mi usuario tiene permiso?</li><li>¿La función está disponible para mi cuenta/plan?</li><li>¿Me cambiaron permisos recientemente? Cierra sesión y vuelve a entrar.</li></ol>
<h2>No encuentro un producto</h2><ol><li>Busca por nombre.</li><li>Busca por código.</li><li>Confirma el almacén.</li><li>Revisa que el producto esté activo.</li><li>Comprueba variante/presentación.</li></ol>
<h2>El POS no me deja continuar</h2><ol><li>¿Tengo caja asignada?</li><li>¿La caja está abierta?</li><li>¿Está siendo usada por otra sesión?</li><li>¿El producto tiene existencia/configuración necesaria?</li></ol>
<h2>La factura SAR se ve mal</h2><ol><li>Detén la emisión normal.</li><li>Revisa emisor y RTN.</li><li>Revisa punto de emisión.</li><li>Revisa CAI/rango.</li><li>Revisa fecha límite.</li></ol>
<h2>Sigue igual</h2><p>No hagas diez cambios a la vez: después no sabrás cuál era la causa. Crea un ticket indicando exactamente qué revisaste y adjunta evidencia segura.</p>
HTML,
'prodex-buenas-practicas-operacion' => <<<'HTML'
<div class="manual-intro"><strong>La regla más importante de un ERP es sencilla: registra lo que realmente ocurrió.</strong> Si compraste, registra compra. Si devolviste, registra devolución. Si moviste mercadería, registra transferencia.</div>
<h2>Una historia correcta</h2><p>Imagina una unidad que llega del proveedor, pasa a Bodega Principal, se transfiere a Sucursal Centro y finalmente se vende. Si cada paso se registra con su documento correcto, PRODEX puede explicar dónde estuvo esa unidad.</p>
<h2>Hábitos diarios</h2><ul class="manual-checklist"><li>Cada empleado utiliza su propia cuenta.</li><li>El cajero abre y cierra su caja.</li><li>Se selecciona el almacén correcto.</li><li>Las compras reales se registran como compras.</li><li>Las devoluciones se registran como devoluciones.</li><li>Los movimientos entre ubicaciones se registran como transferencias.</li><li>Las diferencias físicas se investigan antes de ajustar.</li><li>Los gastos se clasifican con criterio consistente.</li><li>Se revisan reportes y alertas periódicamente.</li></ul>
<h2>Lo que debes evitar</h2><ul><li>Compartir usuarios.</li><li>Crear documentos ficticios para “cuadrar”.</li><li>Eliminar operaciones para esconder errores.</li><li>Modificar datos fiscales sin documentación.</li><li>Dar permisos de administrador solo para evitar configurar roles.</li></ul>
<div class="manual-success"><strong>Cuando inventario, ventas, compras y caja cuentan la misma historia, los reportes se vuelven confiables.</strong></div>
HTML,
        ];
        foreach ($guides as $slug => $content) {
            $db->table('kb_articles')->where('slug', $slug)->update(['content' => $content, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Manual content may be edited by SuperAdmin after deployment.
    }
};
