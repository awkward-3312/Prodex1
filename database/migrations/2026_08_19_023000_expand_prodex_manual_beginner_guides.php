<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasTable('kb_articles')) {
            return;
        }

        $db = DB::connection('central');
        $now = now();

        $guides = [
            'prodex-empieza-aqui-configuracion-inicial' => <<<'HTML'
<div class="manual-intro"><strong>Esta guía es para ti si acabas de entrar a PRODEX por primera vez.</strong><br>No necesitas conocer el sistema. Vamos a preparar el negocio en el orden correcto para que después vender sea sencillo.</div>
<h2>Primero entiende esta idea</h2>
<p>PRODEX funciona como un negocio real: primero existe la empresa, luego los lugares donde guardas mercadería, después los productos, las personas que usarán el sistema y finalmente las cajas desde donde venderán. Si intentas vender antes de preparar esas piezas, te faltarán datos durante la venta.</p>
<div class="manual-note"><strong>Ejemplo:</strong> imagina una tienda llamada Mi Tienda. Tiene una bodega principal, vende refrescos y tiene una cajera llamada Ana. En PRODEX primero configuramos Mi Tienda, luego la bodega, después los refrescos, el usuario de Ana y por último la caja que Ana utilizará.</div>
<h2>Paso 1. Revisa los datos de tu empresa</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Configuración.</strong><br>Busca la configuración general de la empresa.</div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Lee cada dato antes de cambiarlo.</strong><br>Confirma nombre del negocio, teléfono, dirección, correo y moneda. Estos datos identifican tu empresa dentro del sistema y algunos pueden aparecer en documentos.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Guarda solamente cuando estés seguro.</strong><br>Si no sabes qué significa un campo técnico, déjalo como está y consulta el Manual PRODEX antes de modificarlo.</div>
<h2>Paso 2. Revisa tus almacenes</h2>
<p>Un <strong>almacén</strong> es el lugar al que PRODEX asigna existencias. Puede representar una bodega, una tienda o una sucursal.</p>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Busca Almacenes en Configuración.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Comprueba que exista el lugar desde donde trabajarás.</strong><br>Si solo tienes una tienda, puedes comenzar con un único almacén. Si tienes varias sucursales, cada una puede necesitar el suyo.</div>
<div class="manual-warning"><strong>No crees almacenes duplicados.</strong> “Bodega”, “Bodega principal” y “Bodega 1” podrían terminar representando el mismo lugar y dividir tu inventario por error.</div>
<h2>Paso 3. Prepara la forma de organizar tus productos</h2>
<p>Antes de crear cien productos, prepara tres cosas sencillas:</p>
<table><thead><tr><th>Dato</th><th>Qué significa</th><th>Ejemplo</th></tr></thead><tbody><tr><td>Categoría</td><td>Grupo al que pertenece el producto.</td><td>Bebidas</td></tr><tr><td>Marca</td><td>Fabricante o marca comercial.</td><td>Coca-Cola</td></tr><tr><td>Unidad</td><td>Cómo cuentas o mides el producto.</td><td>Unidad, caja, kg</td></tr></tbody></table>
<h2>Paso 4. Crea un producto de prueba</h2>
<p>No cargues todo tu inventario de inmediato. Crea primero un producto sencillo y úsalo para aprender.</p>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Ve a Productos y elige Agregar producto.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Escribe un nombre reconocible.</strong><br>Ejemplo: “Agua 600 ml”.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Asigna un código único.</strong><br>El código sirve para distinguirlo de todos los demás productos.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Selecciona categoría, unidad, costo y precio.</strong><br>Recuerda: costo = lo que te cuesta; precio = lo que cobrará tu negocio.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Guarda y vuelve a buscarlo.</strong><br>Si aparece correctamente en la lista, ya entendiste el flujo básico.</div>
<h2>Paso 5. Prepara a las personas que usarán PRODEX</h2>
<p>No es recomendable que todos usen la misma cuenta. Cada empleado debe tener su propio usuario y solamente los permisos necesarios.</p>
<div class="manual-note"><strong>Ejemplo:</strong> una cajera necesita vender y usar su caja; no necesariamente necesita cambiar configuraciones, eliminar productos o administrar usuarios.</div>
<h2>Paso 6. Si usarás POS, prepara la caja</h2>
<p>La <strong>caja física</strong> representa el punto de cobro. Debes crearla y asignarla al usuario que venderá. Después el cajero podrá abrir su turno con el efectivo inicial.</p>
<h2>Paso 7. Si emitirás factura fiscal en Honduras</h2>
<p>Antes de vender con factura SAR debes completar la configuración fiscal: datos del emisor, punto de emisión, CAI, rango autorizado y fecha límite. No inventes estos datos; utiliza exactamente la información de tu autorización.</p>
<h2>Tu primera prueba</h2>
<ol><li>Inicia sesión con el usuario que venderá.</li><li>Abre la caja si el sistema lo solicita.</li><li>Entra al POS.</li><li>Busca el producto de prueba.</li><li>Agrégalo a la venta.</li><li>Revisa cantidad, precio y total.</li><li>Procesa una venta de prueba únicamente cuando la configuración sea correcta.</li></ol>
<h2>Lista final: ¿ya puedo comenzar?</h2>
<ul class="manual-checklist"><li>Reconozco el almacén que utilizaré.</li><li>Tengo al menos un producto correctamente creado.</li><li>El precio de venta es correcto.</li><li>Cada empleado tiene su propio usuario.</li><li>La caja está creada y asignada si usaré POS.</li><li>Los datos SAR están completos si emitiré factura fiscal.</li></ul>
<div class="manual-success"><strong>Si puedes marcar todo lo anterior, ya tienes la base necesaria para comenzar.</strong> Continúa con las guías de Productos, POS y Caja para aprender cada operación con más detalle.</div>
HTML,
            'prodex-crear-producto' => <<<'HTML'
<div class="manual-intro"><strong>Objetivo:</strong> crear un producto que pueda encontrarse, venderse y controlarse correctamente en inventario. Esta guía explica qué significa cada dato importante.</div>
<h2>Antes de tocar “Agregar producto”</h2>
<p>Piensa en un producto real de tu negocio. Para el ejemplo usaremos <strong>Agua purificada 600 ml</strong>. Sabemos que pertenece a Bebidas, se vende por unidad, cuesta L 10 y se vende a L 15.</p>
<h2>Paso 1. Abre el formulario</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>En el menú entra a Productos.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona Agregar producto.</strong><br>Se abrirá la ficha donde PRODEX guardará la información del artículo.</div>
<h2>Paso 2. Nombre y código</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Nombre:</strong> escribe algo que cualquier empleado pueda reconocer. “Agua purificada 600 ml” es mejor que “Agua”.</div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Código:</strong> debe identificar únicamente ese producto. Si dos productos comparten código, será más fácil vender o ajustar el equivocado.</div>
<div class="manual-note"><strong>Si el producto ya trae código de barras:</strong> utiliza el código real cuando tu operación lo requiera. Si vas a escanearlo en caja, prueba el lector antes de cargar muchos productos.</div>
<h2>Paso 3. Categoría, marca y unidad</h2>
<p><strong>Categoría</strong> responde “¿qué tipo de producto es?”. <strong>Marca</strong> responde “¿de qué marca es?”. <strong>Unidad</strong> responde “¿cómo lo cuento?”.</p>
<table><thead><tr><th>Campo</th><th>Ejemplo</th></tr></thead><tbody><tr><td>Categoría</td><td>Bebidas</td></tr><tr><td>Marca</td><td>Marca del fabricante</td></tr><tr><td>Unidad</td><td>Unidad</td></tr></tbody></table>
<h2>Paso 4. Costo y precio</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Costo:</strong> coloca cuánto le cuesta el producto al negocio según tu forma de manejar costos.</div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Precio:</strong> coloca cuánto pagarás al venderlo al cliente.</div>
<div class="manual-warning"><strong>No confundas costo con precio.</strong> Si compras a L 10 y vendes a L 15, el costo no es L 15. Revisar esto es importante para que los reportes de rentabilidad tengan sentido.</div>
<h2>Paso 5. Impuesto</h2>
<p>Revisa el impuesto que corresponde al producto. En Honduras no todos los productos necesariamente tienen el mismo tratamiento fiscal. Si no estás seguro del impuesto aplicable, confírmalo con la persona responsable de la contabilidad/facturación antes de emitir documentos.</p>
<h2>Paso 6. Alerta de stock</h2>
<p>La alerta sirve para avisarte que quedan pocas unidades. Por ejemplo, si normalmente quieres volver a comprar cuando quedan 5, configura una alerta coherente con esa necesidad.</p>
<h2>Paso 7. Guarda y comprueba</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Relee la ficha.</strong> Mira especialmente nombre, código, unidad, costo y precio.</div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Guarda.</strong></div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Busca el producto en la lista.</strong> Confirma que aparece una sola vez.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Comprueba el POS.</strong> Si es un producto vendible, búscalo en el POS y confirma que muestra el precio esperado.</div>
<h2>¿Cuándo usar opciones avanzadas?</h2>
<details class="manual-details"><summary>Variantes</summary><p>Úsalas cuando un mismo artículo tiene opciones como talla o color. Ejemplo: Camiseta / talla S, M y L. Si son productos completamente distintos, suele ser más claro manejarlos como productos separados.</p></details>
<details class="manual-details"><summary>Promoción</summary><p>Úsala para un precio especial durante un período. Revisa inicio, fin y precio antes de anunciar la promoción.</p></details>
<details class="manual-details"><summary>Garantía</summary><p>Úsala cuando necesitas registrar cuánto tiempo cubres el producto.</p></details>
<details class="manual-details"><summary>Serial / IMEI</summary><p>Úsalo para artículos que deben identificarse unidad por unidad, como ciertos teléfonos o equipos.</p></details>
<h2>Errores comunes</h2>
<ul><li>Crear nuevamente un producto que ya existía.</li><li>Usar un nombre demasiado general.</li><li>Confundir costo y precio.</li><li>Elegir una unidad incorrecta.</li><li>No revisar el impuesto.</li><li>Crear variantes innecesarias.</li></ul>
<div class="manual-success"><strong>Producto bien creado:</strong> puedes encontrarlo por nombre/código, el precio es correcto y la unidad representa cómo realmente lo vendes.</div>
HTML,
            'prodex-transferir-inventario-almacenes' => <<<'HTML'
<div class="manual-intro"><strong>¿Qué es una transferencia?</strong> Es mover mercadería que ya es tuya desde un almacén a otro. No es una compra y no es una venta.</div>
<h2>Ejemplo sencillo</h2><p>Tienes 20 unidades en “Bodega principal” y necesitas llevar 5 a “Sucursal Centro”. La transferencia le dice a PRODEX de dónde salen esas 5 y a dónde llegan.</p>
<h2>Antes de comenzar</h2><ul class="manual-checklist"><li>Existen el almacén de origen y el de destino.</li><li>Elegiste el producto correcto.</li><li>La cantidad que quieres mover existe en el origen.</li></ul>
<h2>Haz la transferencia</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Entra a Transferencias.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Elige el almacén de origen.</strong><br>Es el lugar de donde sale físicamente la mercadería.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Elige el almacén de destino.</strong><br>Es el lugar que recibirá la mercadería. No selecciones el mismo almacén en ambos campos.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Agrega el producto.</strong><br>Verifica nombre y código, especialmente si tienes artículos parecidos.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Escribe la cantidad.</strong><br>En el ejemplo serían 5 unidades.</div>
<div class="manual-step"><span class="manual-step-number">6</span><strong>Revisa antes de guardar.</strong><br>Lee en voz mental: “voy a mover 5 Agua 600 ml de Bodega principal a Sucursal Centro”. Si eso describe exactamente el movimiento físico, continúa.</div>
<div class="manual-step"><span class="manual-step-number">7</span><strong>Guarda y verifica las existencias.</strong><br>Comprueba el stock de ambos almacenes según el estado final del movimiento.</div>
<div class="manual-warning"><strong>No uses una transferencia para corregir un inventario que nunca se movió físicamente.</strong> Para diferencias de conteo utiliza el proceso de ajuste/conteo correspondiente.</div>
HTML,
            'prodex-ajuste-conteo-inventario' => <<<'HTML'
<div class="manual-intro"><strong>Usa esta guía cuando cuentas físicamente un producto y el número no coincide con PRODEX.</strong> Primero investigamos; ajustar es el último paso, no el primero.</div>
<h2>Ejemplo</h2><p>PRODEX dice que hay 10 unidades, pero al contar en el estante encuentras 8. Hay una diferencia de 2. Antes de ajustar, debemos comprobar que no exista una venta, compra o transferencia pendiente.</p>
<h2>Paso 1. Confirma dónde estás contando</h2><p>Revisa el almacén. Un producto puede tener existencias diferentes en cada almacén.</p>
<h2>Paso 2. Cuenta nuevamente</h2><p>Haz un segundo conteo. Si es posible, pide a otra persona que confirme. Esto evita modificar el sistema por un error de conteo.</p>
<h2>Paso 3. Busca la causa</h2><ul><li>¿Hubo una venta que no se registró?</li><li>¿Entró una compra que todavía no está registrada?</li><li>¿Se movió mercadería a otra sucursal?</li><li>¿Hubo devolución, daño o pérdida?</li></ul>
<div class="manual-warning"><strong>Si encuentras la operación que falta, registra/corrige esa operación cuando corresponda.</strong> No uses un ajuste para esconder el origen del problema.</div>
<h2>Paso 4. Si la diferencia es real, registra el ajuste</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Selecciona el almacén correcto.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Busca el producto por nombre o código.</strong></div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Indica la corrección necesaria según la pantalla.</strong><br>Lee con cuidado si el campo solicita cantidad final o diferencia; no asumas.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Guarda y vuelve a consultar el stock.</strong></div>
<h2>Después del ajuste</h2><p>El número en PRODEX debe coincidir con la existencia física que confirmaste. Si las diferencias se repiten con frecuencia, revisa el proceso operativo en vez de hacer ajustes constantes.</p>
HTML,
        ];

        foreach ($guides as $slug => $content) {
            $db->table('kb_articles')
                ->where('slug', $slug)
                ->update(['content' => $content, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Documentation updates are intentionally not rolled back to older text.
    }
};
