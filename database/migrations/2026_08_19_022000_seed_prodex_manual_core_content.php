<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::connection('central')->hasTable('kb_categories')
            || ! Schema::connection('central')->hasTable('kb_articles')
        ) {
            return;
        }

        $db = DB::connection('central');
        $now = now();

        $categories = [
            ['name' => 'Primeros pasos', 'slug' => 'prodex-primeros-pasos', 'description' => 'Configuración inicial y recorrido básico para comenzar a trabajar con PRODEX.', 'icon' => 'bi-rocket-takeoff', 'sort_order' => 10],
            ['name' => 'Productos e inventario', 'slug' => 'prodex-productos-inventario', 'description' => 'Productos, categorías, unidades, existencias, ajustes y transferencias.', 'icon' => 'bi-box-seam', 'sort_order' => 20],
            ['name' => 'Ventas y POS', 'slug' => 'prodex-ventas-pos', 'description' => 'Punto de venta, cobros, clientes, borradores y devoluciones.', 'icon' => 'bi-cart-check', 'sort_order' => 30],
            ['name' => 'Caja y pagos', 'slug' => 'prodex-caja-pagos', 'description' => 'Cajas físicas, apertura, cierre y control del efectivo.', 'icon' => 'bi-cash-register', 'sort_order' => 40],
            ['name' => 'Compras', 'slug' => 'prodex-compras', 'description' => 'Registro de compras, proveedores, costos y recepción de mercadería.', 'icon' => 'bi-bag-check', 'sort_order' => 50],
            ['name' => 'Facturación SAR Honduras', 'slug' => 'prodex-facturacion-sar', 'description' => 'Datos fiscales, puntos de emisión, CAI, rangos autorizados y factura SAR.', 'icon' => 'bi-receipt-cutoff', 'sort_order' => 60],
            ['name' => 'Clientes y proveedores', 'slug' => 'prodex-clientes-proveedores', 'description' => 'Gestión de contactos comerciales y datos necesarios para operar.', 'icon' => 'bi-people', 'sort_order' => 70],
            ['name' => 'Usuarios y permisos', 'slug' => 'prodex-usuarios-permisos', 'description' => 'Usuarios, roles, permisos, almacenes y cajas asignadas.', 'icon' => 'bi-shield-check', 'sort_order' => 80],
            ['name' => 'Contabilidad y reportes', 'slug' => 'prodex-contabilidad-reportes', 'description' => 'Gastos, depósitos, cuentas y reportes para controlar el negocio.', 'icon' => 'bi-calculator', 'sort_order' => 90],
            ['name' => 'Ayuda y cuenta', 'slug' => 'prodex-ayuda-cuenta', 'description' => 'Soporte, planes, buenas prácticas y solución de problemas comunes.', 'icon' => 'bi-life-preserver', 'sort_order' => 100],
        ];

        $categoryIds = [];
        foreach ($categories as $category) {
            $existing = $db->table('kb_categories')->where('slug', $category['slug'])->first();
            if (! $existing) {
                $id = $db->table('kb_categories')->insertGetId(array_merge($category, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } else {
                $id = $existing->id;
            }
            $categoryIds[$category['slug']] = $id;
        }

        $articles = [
            [
                'category' => 'prodex-primeros-pasos',
                'title' => 'Empieza aquí: qué configurar antes de hacer tu primera venta',
                'slug' => 'prodex-empieza-aqui-configuracion-inicial',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro"><strong>Objetivo:</strong> dejar tu espacio de trabajo listo para vender sin encontrarte con campos, almacenes o cajas pendientes a mitad de una operación.</div>
<h2>Orden recomendado de configuración</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Revisa los datos de tu empresa.</strong><br>En Configuración confirma el nombre comercial, datos de contacto, dirección, moneda y demás información general. Estos datos pueden utilizarse en documentos y pantallas del sistema.</div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Crea o revisa tus almacenes.</strong><br>Los productos, compras, ventas y movimientos de inventario necesitan un almacén desde donde operar.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Prepara categorías, marcas y unidades.</strong><br>Hazlo antes de cargar muchos productos. Una estructura clara desde el inicio facilita búsquedas y reportes.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Crea tus productos.</strong><br>Registra nombre, código, categoría, costo, precio y unidad. Añade opciones avanzadas solo cuando las necesites.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Crea usuarios y define permisos.</strong><br>Cada persona debe tener únicamente el acceso necesario. Si trabajará en una sucursal o caja específica, asígnala desde su usuario.</div>
<div class="manual-step"><span class="manual-step-number">6</span><strong>Configura las cajas físicas.</strong><br>Si venderás desde POS, crea las cajas registradoras y asigna los cajeros correspondientes.</div>
<div class="manual-step"><span class="manual-step-number">7</span><strong>Si facturas en Honduras, completa Facturación SAR.</strong><br>Configura RTN, datos fiscales, puntos de emisión, CAI y rangos autorizados antes de emitir documentos fiscales.</div>
<h2>Comprobación antes de comenzar</h2>
<ul class="manual-checklist"><li>Hay al menos un almacén activo.</li><li>Los productos principales ya tienen precio de venta.</li><li>El usuario que venderá tiene permisos y acceso al almacén correcto.</li><li>La caja física está asignada al cajero, si aplica.</li><li>La configuración SAR está completa si emitirás factura fiscal.</li></ul>
<div class="manual-success"><strong>Listo:</strong> si todo lo anterior está completo, ya puedes abrir caja y realizar una venta de prueba en el POS.</div>
HTML,
            ],
            [
                'category' => 'prodex-primeros-pasos',
                'title' => 'Cómo orientarte dentro de PRODEX',
                'slug' => 'prodex-como-orientarte-en-el-sistema',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">PRODEX organiza las funciones por áreas del negocio. No todos los usuarios ven las mismas opciones: el menú depende de los permisos y de las funciones disponibles en el plan.</div>
<h2>Áreas principales</h2>
<table><thead><tr><th>Área</th><th>Qué haces allí</th></tr></thead><tbody><tr><td>Productos</td><td>Crear artículos, categorías, marcas, unidades, códigos y opciones de inventario.</td></tr><tr><td>Compras</td><td>Registrar mercadería comprada a proveedores.</td></tr><tr><td>Ventas / POS</td><td>Vender, cobrar, imprimir documentos y consultar ventas.</td></tr><tr><td>Personas</td><td>Administrar clientes y proveedores.</td></tr><tr><td>Usuarios</td><td>Crear usuarios, roles y permisos.</td></tr><tr><td>Contabilidad</td><td>Administrar cuentas, gastos, depósitos y movimientos contables disponibles.</td></tr><tr><td>Reportes</td><td>Consultar ventas, compras, inventario, rentabilidad y otros indicadores.</td></tr><tr><td>Configuración</td><td>Personalizar el sistema y administrar almacenes, cajas y parámetros.</td></tr></tbody></table>
<h2>Si no encuentras una opción</h2>
<ol><li>Confirma que estás buscando en el módulo correcto.</li><li>Revisa si tu usuario tiene permiso para esa función.</li><li>Si la función depende del plan, verifica que esté habilitada para tu suscripción.</li><li>Si continúa sin aparecer, crea un ticket desde el Centro de soporte.</li></ol>
<div class="manual-note"><strong>Consejo:</strong> utiliza el buscador del Manual PRODEX escribiendo lo que quieres hacer, por ejemplo “crear producto”, “CAI”, “cerrar caja” o “registrar compra”.</div>
HTML,
            ],
            [
                'category' => 'prodex-productos-inventario',
                'title' => 'Cómo crear un producto correctamente',
                'slug' => 'prodex-crear-producto',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro"><strong>Antes de empezar:</strong> conviene tener creadas la categoría y la unidad que utilizará el producto.</div>
<h2>Crear el producto</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Productos y selecciona Agregar producto.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Escribe el nombre y código.</strong><br>Usa un nombre fácil de reconocer y un código único. Si manejas códigos de barras, selecciona también la simbología adecuada.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Selecciona categoría y, si aplica, marca.</strong><br>Esto facilita filtros, búsquedas y reportes.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Ingresa costo y precio.</strong><br>El costo representa lo que te cuesta el producto; el precio es el valor al que lo venderás.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Configura las unidades.</strong><br>Selecciona la unidad principal y, cuando corresponda, las unidades de compra y venta.</div>
<div class="manual-step"><span class="manual-step-number">6</span><strong>Define la alerta de stock e impuesto.</strong><br>La alerta te ayuda a detectar existencias bajas. Revisa el impuesto aplicable antes de guardar.</div>
<div class="manual-step"><span class="manual-step-number">7</span><strong>Guarda el producto.</strong><br>Después de guardar, verifica que aparezca correctamente en la lista y en el POS.</div>
<h2>Opciones avanzadas</h2>
<details class="manual-details"><summary>Variantes</summary><p>Utilízalas cuando el mismo producto tenga opciones como talla, color u otra característica. Evita crear variantes si realmente son productos independientes.</p></details>
<details class="manual-details"><summary>Promoción</summary><p>Puedes definir un precio promocional y un período de inicio y fin cuando esta opción esté disponible.</p></details>
<details class="manual-details"><summary>Garantía</summary><p>Configura duración y unidad de tiempo cuando el producto requiere seguimiento de garantía.</p></details>
<details class="manual-details"><summary>Números de serie / IMEI</summary><p>Si tu negocio rastrea equipos individualmente, utiliza el control de seriales para registrar cada unidad específica.</p></details>
<div class="manual-warning"><strong>Evita duplicados:</strong> antes de crear un producto nuevo, búscalo por nombre o código.</div>
HTML,
            ],
            [
                'category' => 'prodex-productos-inventario',
                'title' => 'Categorías, marcas y unidades: cómo organizarlas',
                'slug' => 'prodex-categorias-marcas-unidades',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">Una buena organización evita terminar con productos difíciles de encontrar y reportes poco útiles.</div>
<h2>Categorías</h2><p>Úsalas para agrupar productos por tipo: por ejemplo, Bebidas, Electrónica, Repuestos o Papelería. Mantén nombres claros y evita crear categorías casi idénticas.</p>
<h2>Marcas</h2><p>La marca identifica al fabricante o línea comercial. Es útil para búsquedas y análisis de ventas por marca.</p>
<h2>Unidades</h2><p>La unidad define cómo se mide el producto: unidad, caja, kilogramo, metro, litro, etc. Si compras en una presentación y vendes en otra, revisa las unidades de compra y venta.</p>
<h2>Buenas prácticas</h2><ul class="manual-checklist"><li>Define la estructura antes de importar muchos productos.</li><li>Usa nombres cortos y consistentes.</li><li>No crees una marca o categoría nueva si ya existe con otro uso de mayúsculas.</li><li>Revisa las unidades antes de registrar inventario.</li></ul>
HTML,
            ],
            [
                'category' => 'prodex-productos-inventario',
                'title' => 'Cómo hacer un ajuste o conteo de inventario',
                'slug' => 'prodex-ajuste-conteo-inventario',
                'sort_order' => 30,
                'content' => <<<'HTML'
<div class="manual-intro">Utiliza un ajuste cuando la existencia real no coincide con la registrada. Para revisiones físicas amplias, utiliza las herramientas de conteo de inventario disponibles en Productos.</div>
<h2>Antes de ajustar</h2><ul class="manual-checklist"><li>Confirma el almacén.</li><li>Cuenta físicamente el producto.</li><li>Revisa si hay compras, ventas o transferencias pendientes de registrar.</li></ul>
<h2>Proceso recomendado</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Selecciona el almacén correcto.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Busca el producto.</strong><br>Verifica código y nombre para no modificar otro artículo parecido.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Registra la diferencia encontrada.</strong><br>El ajuste debe reflejar la corrección necesaria para que el sistema coincida con el conteo real.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Guarda y vuelve a consultar el stock.</strong></div>
<div class="manual-warning"><strong>No uses ajustes para ocultar errores de operación.</strong> Si el problema viene de una compra o venta mal registrada, corrige el documento correspondiente cuando sea posible.</div>
HTML,
            ],
            [
                'category' => 'prodex-productos-inventario',
                'title' => 'Cómo transferir inventario entre almacenes',
                'slug' => 'prodex-transferir-inventario-almacenes',
                'sort_order' => 40,
                'content' => <<<'HTML'
<div class="manual-intro">Las transferencias permiten mover existencias de un almacén a otro sin registrar una compra ni una venta.</div>
<h2>Crear una transferencia</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Transferencias.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona almacén de origen y destino.</strong><br>Deben ser almacenes diferentes.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Agrega los productos y cantidades.</strong><br>Confirma que el origen tenga existencia suficiente.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Revisa el movimiento y guarda.</strong></div>
<h2>Después de guardar</h2><p>Consulta el stock de ambos almacenes y verifica que el movimiento corresponda con la mercadería trasladada físicamente.</p>
<div class="manual-note"><strong>Consejo:</strong> registra la transferencia en el momento del traslado para evitar que dos sucursales trabajen con existencias equivocadas.</div>
HTML,
            ],
            [
                'category' => 'prodex-ventas-pos',
                'title' => 'Cómo realizar una venta completa en el POS',
                'slug' => 'prodex-realizar-venta-pos',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro"><strong>Objetivo:</strong> registrar una venta desde que el cliente llega hasta el cobro y la emisión del comprobante.</div>
<h2>Antes de vender</h2><ul class="manual-checklist"><li>El usuario tiene acceso al almacén correcto.</li><li>La caja física está asignada si tu operación la utiliza.</li><li>La caja está abierta.</li><li>Los productos tienen precio y existencia.</li></ul>
<h2>Registrar la venta</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Entra al Punto de Venta.</strong><br>Confirma el almacén que aparece en la parte superior.</div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona el cliente.</strong><br>Para una venta rápida puedes utilizar el cliente general configurado; si necesitas datos fiscales o historial individual, selecciona o crea el cliente correspondiente.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Agrega productos.</strong><br>Búscalos por nombre/código o utiliza el escáner. Revisa cantidad y precio antes de continuar.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Revisa impuestos y descuentos.</strong><br>Comprueba el subtotal y el total a pagar.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Presiona Pagar ahora.</strong><br>Selecciona el método de pago y registra el monto recibido según corresponda.</div>
<div class="manual-step"><span class="manual-step-number">6</span><strong>Finaliza y entrega el comprobante.</strong><br>Después del pago, PRODEX registra la venta y permite visualizar/imprimir la factura o comprobante correspondiente.</div>
<h2>Si algo no cuadra</h2>
<details class="manual-details"><summary>El producto no aparece</summary><p>Verifica que esté activo, que pertenezca al inventario disponible y que estés trabajando en el almacén correcto.</p></details>
<details class="manual-details"><summary>No puedo vender por falta de caja</summary><p>Confirma que el usuario esté asignado a una caja física y que esa caja no esté abierta por otro usuario.</p></details>
<details class="manual-details"><summary>La factura SAR no muestra CAI/rango</summary><p>Revisa Configuración SAR, el punto de emisión y que exista un rango activo y vigente.</p></details>
HTML,
            ],
            [
                'category' => 'prodex-ventas-pos',
                'title' => 'Qué revisar antes de cobrar una venta',
                'slug' => 'prodex-revisar-venta-antes-cobrar',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">Una revisión de pocos segundos antes de cobrar evita la mayoría de correcciones posteriores.</div>
<h2>Lista rápida</h2><ul class="manual-checklist"><li>Cliente correcto.</li><li>Almacén correcto.</li><li>Productos y variantes correctos.</li><li>Cantidades correctas.</li><li>Precio y descuentos autorizados.</li><li>Impuesto correcto.</li><li>Total final confirmado con el cliente.</li><li>Método de pago correcto.</li></ul>
<div class="manual-warning"><strong>Después de finalizar una venta:</strong> no edites datos a la ligera. Si debes revertir una operación, utiliza el flujo de devolución o corrección que corresponda para conservar trazabilidad.</div>
HTML,
            ],
            [
                'category' => 'prodex-ventas-pos',
                'title' => 'Cómo manejar una devolución de venta',
                'slug' => 'prodex-devolucion-venta',
                'sort_order' => 30,
                'content' => <<<'HTML'
<div class="manual-intro">Las devoluciones deben registrarse desde el módulo correspondiente para que inventario, cliente y reportes permanezcan coherentes.</div>
<h2>Proceso recomendado</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Localiza la venta original.</strong><br>Confirma referencia, fecha, cliente y productos.</div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Abre el flujo de devolución de venta.</strong></div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Selecciona únicamente los artículos y cantidades que realmente regresan.</strong></div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Revisa importes y guarda la devolución.</strong></div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Verifica inventario y saldo.</strong><br>Comprueba que el efecto de la devolución sea el esperado.</div>
<div class="manual-note">Conserva la referencia de la operación original. Esto facilita auditorías y atención al cliente.</div>
HTML,
            ],
            [
                'category' => 'prodex-caja-pagos',
                'title' => 'Cómo configurar una caja física y asignar cajeros',
                'slug' => 'prodex-configurar-caja-fisica',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro">Una <strong>caja física</strong> representa la gaveta o punto de cobro real utilizado por un cajero en un almacén.</div>
<h2>Crear la caja</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Ve a Configuración → Cajas físicas.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Crea una nueva caja.</strong><br>Usa un nombre que identifique fácilmente el punto, por ejemplo “Caja 1 - Principal”.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Asóciala al almacén correspondiente.</strong></div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Asigna los usuarios que podrán utilizarla.</strong><br>También puedes gestionar asignaciones desde el usuario cuando esa opción esté disponible.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Déjala activa y guarda.</strong></div>
<h2>Reglas importantes</h2><ul class="manual-checklist"><li>Cada cajero debe utilizar la caja que tiene asignada.</li><li>No compartas sesiones de usuario entre cajeros.</li><li>Antes de vender, la caja debe abrirse con su saldo inicial.</li></ul>
<div class="manual-warning">Si PRODEX indica que una caja ya está abierta por otra persona, no fuerces una segunda apertura. Verifica quién tiene la sesión activa y cierra el turno correctamente.</div>
HTML,
            ],
            [
                'category' => 'prodex-caja-pagos',
                'title' => 'Cómo abrir y cerrar caja correctamente',
                'slug' => 'prodex-abrir-cerrar-caja',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">Abrir y cerrar caja diariamente permite comparar el efectivo físico con lo que PRODEX espera según las operaciones registradas.</div>
<h2>Abrir caja</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Entra a la apertura de caja.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona tu caja física asignada.</strong></div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Cuenta el efectivo inicial.</strong><br>Ingresa únicamente el dinero que realmente está dentro de la gaveta al iniciar el turno.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Confirma la apertura.</strong></div>
<h2>Cerrar caja</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Finaliza las operaciones pendientes.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Cuenta físicamente el efectivo.</strong><br>Haz el conteo antes de retirar dinero de la gaveta.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Compara con el saldo esperado por el sistema.</strong><br>PRODEX considera saldo inicial, efectivo cobrado y movimientos registrados.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Registra el conteo final y revisa la diferencia.</strong></div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Cierra el turno.</strong></div>
<div class="manual-warning"><strong>Una diferencia no debe ignorarse.</strong> Si hay sobrante o faltante, revisa ventas en efectivo, cambios entregados, retiros y entradas antes de cerrar definitivamente.</div>
HTML,
            ],
            [
                'category' => 'prodex-compras',
                'title' => 'Cómo registrar una compra de mercadería',
                'slug' => 'prodex-registrar-compra',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro">Registrar correctamente una compra mantiene actualizados costos, existencias y movimientos con proveedores.</div>
<h2>Crear la compra</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Compras → Agregar compra.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Indica fecha, referencia y proveedor.</strong><br>La referencia debe ayudarte a relacionar el registro con la factura u orden recibida.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Selecciona estado y almacén.</strong><br>El almacén define dónde ingresará la mercadería.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Busca y agrega los productos.</strong><br>Puedes buscarlos por nombre/código; en operaciones compatibles también puedes utilizar lector de código de barras.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Revisa costo unitario y cantidad.</strong><br>Confirma descuento e impuesto por línea si corresponde.</div>
<div class="manual-step"><span class="manual-step-number">6</span><strong>Revisa totales generales.</strong><br>PRODEX permite registrar impuesto de la orden, descuento y envío cuando aplican.</div>
<div class="manual-step"><span class="manual-step-number">7</span><strong>Añade una nota si necesitas contexto y guarda.</strong></div>
<h2>Productos con serial o IMEI</h2><p>Si el producto tiene seguimiento individual, registra los números de serie solicitados antes de finalizar. Cada unidad física debe corresponder a un serial válido.</p>
<div class="manual-success">Después de guardar, consulta el inventario del almacén y confirma que las cantidades ingresaron como esperabas.</div>
HTML,
            ],
            [
                'category' => 'prodex-compras',
                'title' => 'Qué hacer cuando una compra no coincide con lo recibido',
                'slug' => 'prodex-compra-no-coincide-recepcion',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">Si la factura del proveedor y la mercadería física no coinciden, evita ajustar inventario sin antes identificar la causa.</div>
<h2>Revisión</h2><ol><li>Compara referencia y proveedor.</li><li>Confirma el almacén de recepción.</li><li>Cuenta nuevamente las cantidades.</li><li>Verifica variantes, seriales o unidades de compra.</li><li>Revisa costo, descuento, impuesto y envío.</li></ol>
<h2>Cómo corregir</h2><p>Si el error está en una compra recién registrada y tu permiso lo permite, corrige el documento siguiendo el flujo de edición correspondiente. Si la mercadería será devuelta al proveedor, utiliza una devolución de compra para mantener trazabilidad.</p>
<div class="manual-warning">No compenses una compra equivocada creando movimientos ficticios. Los reportes financieros e inventario dependen de documentos coherentes.</div>
HTML,
            ],
            [
                'category' => 'prodex-facturacion-sar',
                'title' => 'Configuración inicial de Facturación SAR Honduras',
                'slug' => 'prodex-sar-configuracion-inicial',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro"><strong>Importante:</strong> completa esta configuración con la información fiscal autorizada para tu empresa. Si tienes dudas legales o tributarias, confirma los datos con tu contador o con la documentación emitida por el SAR.</div>
<h2>Datos fiscales del emisor</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Contabilidad → Facturación SAR.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Completa RTN.</strong><br>Escribe el RTN de la empresa exactamente como corresponde.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Completa Nombre legal / Razón social y Nombre comercial.</strong></div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Registra actividad económica, teléfono, correo fiscal y dirección fiscal.</strong></div>
<h2>Parámetros tributarios</h2><p>Revisa la tasa general de ISV, la tasa reducida cuando aplique y la moneda fiscal. Para operaciones locales en Honduras la moneda habitual es HNL, pero debes usar la configuración que corresponda a tu operación.</p>
<h2>Lo que falta después</h2><ul class="manual-checklist"><li>Crear al menos un punto de emisión.</li><li>Asociarlo al almacén/caja cuando corresponda.</li><li>Registrar el CAI.</li><li>Registrar el rango autorizado y su fecha límite de emisión.</li></ul>
<div class="manual-warning">No emitas una factura fiscal de prueba con datos inventados en un tenant de producción. Utiliza únicamente información autorizada.</div>
HTML,
            ],
            [
                'category' => 'prodex-facturacion-sar',
                'title' => 'Cómo crear un punto de emisión SAR',
                'slug' => 'prodex-sar-punto-emision',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">El punto de emisión relaciona la numeración fiscal con el lugar o caja desde donde se emiten documentos.</div>
<h2>Crear el punto</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>En Facturación SAR busca la sección Puntos de emisión.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Ingresa el código de establecimiento y el código de punto.</strong><br>Utiliza los códigos que correspondan a tu autorización.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Asigna un nombre claro.</strong><br>Por ejemplo, “Caja principal” o el nombre interno que permita reconocerlo.</div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Agrega dirección si aplica.</strong></div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Relaciona almacén y caja física.</strong><br>Esto ayuda a que la venta utilice el punto que corresponde a esa operación.</div>
<div class="manual-step"><span class="manual-step-number">6</span><strong>Activa y guarda.</strong></div>
<div class="manual-note">Si no aparecen almacenes o cajas para seleccionar, créalos primero desde Configuración.</div>
HTML,
            ],
            [
                'category' => 'prodex-facturacion-sar',
                'title' => 'Cómo registrar CAI y rango autorizado',
                'slug' => 'prodex-sar-cai-rango-autorizado',
                'sort_order' => 30,
                'content' => <<<'HTML'
<div class="manual-intro">El CAI y el rango autorizado controlan qué numeración fiscal puede emitir PRODEX para un punto de emisión.</div>
<h2>Antes de registrar</h2><p>Ten a mano el documento de autorización correspondiente. Necesitarás CAI, número inicial, número final y fechas.</p>
<h2>Registrar el rango</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>En Facturación SAR abre CAI y rangos autorizados.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona el punto de emisión.</strong></div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Escribe el CAI exactamente como fue autorizado.</strong></div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Registra número inicial y número final.</strong><br>No alteres ceros, estructura ni secuencia.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Registra fecha de recepción y fecha límite de emisión.</strong></div>
<div class="manual-step"><span class="manual-step-number">6</span><strong>Activa el rango y guarda.</strong></div>
<h2>Controles recomendados</h2><ul class="manual-checklist"><li>El rango pertenece al punto de emisión correcto.</li><li>La fecha límite no ha vencido.</li><li>La numeración inicial/final coincide con la autorización.</li><li>No existe otro rango activo incompatible para la misma numeración.</li></ul>
<div class="manual-warning"><strong>Fecha límite de emisión:</strong> es la fecha de vencimiento que debe respetarse para el uso del CAI/rango. No continúes emitiendo desde un rango vencido.</div>
HTML,
            ],
            [
                'category' => 'prodex-facturacion-sar',
                'title' => 'Qué revisar en una factura SAR emitida desde el POS',
                'slug' => 'prodex-sar-revisar-factura-pos',
                'sort_order' => 40,
                'content' => <<<'HTML'
<div class="manual-intro">Después de configurar SAR, realiza una revisión visual de una factura de prueba antes de comenzar a facturar normalmente.</div>
<h2>Datos fiscales</h2><ul class="manual-checklist"><li>Nombre o razón social del emisor.</li><li>RTN correcto.</li><li>Dirección y datos fiscales configurados.</li><li>Identificación del documento como FACTURA cuando corresponde.</li></ul>
<h2>Numeración y autorización</h2><ul class="manual-checklist"><li>Número fiscal con establecimiento, punto y correlativo.</li><li>CAI.</li><li>Rango autorizado.</li><li>Fecha límite de emisión / vencimiento del CAI.</li></ul>
<h2>Información de la venta</h2><ul class="manual-checklist"><li>Fecha.</li><li>Cliente.</li><li>Productos, cantidades y precios.</li><li>Subtotal, ISV y total.</li><li>Método/monto pagado cuando el formato lo muestra.</li></ul>
<div class="manual-warning">Si el número fiscal, CAI, rango o fecha límite no corresponde, detén la emisión y corrige la configuración antes de continuar.</div>
HTML,
            ],
            [
                'category' => 'prodex-clientes-proveedores',
                'title' => 'Cuándo crear un cliente y qué datos guardar',
                'slug' => 'prodex-crear-cliente',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro">Crear clientes individuales mejora el historial de ventas, seguimiento de pagos y emisión de documentos con datos específicos.</div>
<h2>Cuándo usar el cliente general</h2><p>Para ventas rápidas al público donde no necesitas identificar a la persona, puedes utilizar el cliente predeterminado configurado.</p>
<h2>Cuándo crear un cliente</h2><ul><li>Necesitas factura con datos del comprador.</li><li>Quieres consultar historial individual.</li><li>Manejas crédito o seguimiento de saldo.</li><li>Necesitas teléfono, correo o dirección para contacto.</li></ul>
<h2>Buenas prácticas</h2><ul class="manual-checklist"><li>Busca primero para evitar duplicados.</li><li>Escribe el nombre completo o razón social de forma consistente.</li><li>Verifica identificación fiscal cuando sea necesaria.</li><li>Guarda un teléfono o correo útil para seguimiento.</li></ul>
HTML,
            ],
            [
                'category' => 'prodex-clientes-proveedores',
                'title' => 'Cómo organizar tus proveedores',
                'slug' => 'prodex-organizar-proveedores',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">Un proveedor bien registrado facilita compras, pagos, devoluciones y reportes.</div>
<h2>Información útil</h2><p>Registra nombre o razón social, teléfono, correo, dirección y datos fiscales/comerciales que tu proceso necesite.</p>
<h2>Antes de crear uno nuevo</h2><ol><li>Búscalo por nombre.</li><li>Comprueba si ya existe una variación del mismo proveedor.</li><li>Si existe, actualiza sus datos en lugar de duplicarlo.</li></ol>
<h2>Al registrar una compra</h2><p>Selecciona siempre el proveedor correcto. Esto permite que sus compras, pagos y devoluciones queden agrupados en el mismo historial.</p>
<div class="manual-note">Si una empresa tiene varias sucursales pero fiscalmente es el mismo proveedor, define una política interna para evitar duplicados innecesarios.</div>
HTML,
            ],
            [
                'category' => 'prodex-usuarios-permisos',
                'title' => 'Cómo crear un usuario y asignarlo correctamente',
                'slug' => 'prodex-crear-usuario',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro">Cada persona que utiliza PRODEX debe tener su propio usuario. No compartas credenciales entre empleados.</div>
<h2>Crear usuario</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Ve a Gestión de usuarios y crea un usuario.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Completa nombre, apellido y nombre de usuario.</strong></div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Registra teléfono y correo cuando correspondan.</strong></div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Define una contraseña segura.</strong></div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Selecciona el rol.</strong><br>El rol determina las acciones que podrá realizar.</div>
<div class="manual-step"><span class="manual-step-number">6</span><strong>Asigna almacenes.</strong><br>Si el rol permite acceso por almacén, selecciona únicamente las ubicaciones donde trabajará.</div>
<div class="manual-step"><span class="manual-step-number">7</span><strong>Asigna cajas físicas si será cajero.</strong></div>
<div class="manual-step"><span class="manual-step-number">8</span><strong>Guarda y prueba el acceso.</strong></div>
<div class="manual-warning"><strong>Principio de mínimo acceso:</strong> no conviertas a todos los empleados en administradores. Da solo los permisos necesarios para su función.</div>
HTML,
            ],
            [
                'category' => 'prodex-usuarios-permisos',
                'title' => 'Roles y permisos: cómo decidir qué acceso dar',
                'slug' => 'prodex-roles-permisos',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">Los roles permiten definir perfiles de trabajo: cajero, supervisor, compras, inventario, contabilidad, etc.</div>
<h2>Diseña roles por función</h2><p>En lugar de crear permisos diferentes para cada persona, crea roles que representen puestos reales de tu negocio.</p>
<h2>Ejemplo de cajero</h2><ul><li>Ver/usar POS y ventas necesarias.</li><li>Acceso al almacén donde trabaja.</li><li>Acceso a su caja física.</li><li>Sin permisos administrativos que no necesita.</li></ul>
<h2>Ejemplo de encargado de inventario</h2><ul><li>Ver productos.</li><li>Realizar los movimientos de inventario autorizados.</li><li>Consultar almacenes y reportes necesarios.</li></ul>
<h2>Antes de guardar un rol</h2><ul class="manual-checklist"><li>¿Puede ver información sensible que no necesita?</li><li>¿Puede eliminar o editar documentos que debería solo consultar?</li><li>¿Tiene acceso a todos los almacenes o solo a los necesarios?</li><li>¿El rol permite realizar su trabajo sin usar la cuenta de otra persona?</li></ul>
<div class="manual-note">Revisa los roles cuando cambien responsabilidades de personal. Los permisos antiguos suelen ser una fuente de acceso innecesario.</div>
HTML,
            ],
            [
                'category' => 'prodex-contabilidad-reportes',
                'title' => 'Cómo registrar gastos sin perder el control',
                'slug' => 'prodex-registrar-gastos',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro">Registrar los gastos ayuda a que los reportes reflejen mejor la operación real del negocio.</div>
<h2>Antes de registrar</h2><ul class="manual-checklist"><li>Define categorías de gasto coherentes.</li><li>Ten disponible el comprobante o referencia.</li><li>Selecciona la cuenta/almacén correspondiente cuando el formulario lo solicite.</li></ul>
<h2>Proceso</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Gastos y crea un nuevo registro.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Selecciona categoría y fecha.</strong></div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Ingresa importe y detalles.</strong></div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Añade notas o referencia.</strong><br>Incluye información suficiente para reconocer el gasto posteriormente.</div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Guarda y revisa el reporte de gastos.</strong></div>
<div class="manual-warning">No agrupes todos los gastos bajo una sola categoría. Esto hace que el reporte pierda utilidad para tomar decisiones.</div>
HTML,
            ],
            [
                'category' => 'prodex-contabilidad-reportes',
                'title' => 'Reportes que conviene revisar con frecuencia',
                'slug' => 'prodex-reportes-recomendados',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">No necesitas revisar todos los reportes todos los días. Empieza por los que responden preguntas operativas importantes.</div>
<h2>Diariamente</h2><ul><li><strong>Ventas:</strong> cuánto se vendió y por qué canales/usuarios.</li><li><strong>Caja:</strong> cobros y diferencias de cierre.</li><li><strong>Alertas de stock:</strong> productos que requieren reposición.</li></ul>
<h2>Semanalmente</h2><ul><li>Productos más vendidos.</li><li>Compras por proveedor.</li><li>Movimientos y ajustes de inventario.</li><li>Gastos y depósitos.</li></ul>
<h2>Mensualmente</h2><ul><li>Ganancia/pérdida o reportes de rentabilidad disponibles.</li><li>Valoración de inventario.</li><li>Ventas por producto, categoría, marca, usuario o almacén.</li><li>Clientes y proveedores principales.</li></ul>
<div class="manual-note"><strong>Consejo:</strong> compara períodos equivalentes. Una cifra aislada dice menos que su tendencia frente a la semana o mes anterior.</div>
HTML,
            ],
            [
                'category' => 'prodex-ayuda-cuenta',
                'title' => 'Cómo pedir ayuda desde el Centro de soporte',
                'slug' => 'prodex-crear-ticket-soporte',
                'sort_order' => 10,
                'content' => <<<'HTML'
<div class="manual-intro">Cuando necesites ayuda, un ticket con información concreta permite diagnosticar el problema mucho más rápido.</div>
<h2>Antes de crear el ticket</h2><ol><li>Actualiza la página y confirma si el problema continúa.</li><li>Anota qué estabas intentando hacer.</li><li>Identifica el módulo y, si aplica, la referencia del documento.</li><li>Toma una captura donde se vea el mensaje completo, evitando mostrar contraseñas o datos sensibles.</li></ol>
<h2>Crear el ticket</h2>
<div class="manual-step"><span class="manual-step-number">1</span><strong>Abre Centro de soporte.</strong></div>
<div class="manual-step"><span class="manual-step-number">2</span><strong>Crea un ticket con un título específico.</strong><br>Mejor “No puedo abrir Caja 2” que “No funciona”.</div>
<div class="manual-step"><span class="manual-step-number">3</span><strong>Describe los pasos que producen el problema.</strong></div>
<div class="manual-step"><span class="manual-step-number">4</span><strong>Indica qué esperabas y qué ocurrió.</strong></div>
<div class="manual-step"><span class="manual-step-number">5</span><strong>Envía y continúa la conversación desde el mismo ticket.</strong></div>
<h2>Qué no debes enviar</h2><ul><li>Contraseñas.</li><li>Claves privadas o API secretas.</li><li>Datos de tarjetas.</li><li>Información sensible que no sea necesaria para diagnosticar el caso.</li></ul>
HTML,
            ],
            [
                'category' => 'prodex-ayuda-cuenta',
                'title' => 'Soluciones rápidas cuando algo no aparece o no te deja continuar',
                'slug' => 'prodex-soluciones-rapidas',
                'sort_order' => 20,
                'content' => <<<'HTML'
<div class="manual-intro">Muchos bloqueos se deben a permisos, almacén, caja, configuración previa o datos faltantes. Usa esta revisión antes de abrir un ticket.</div>
<h2>No veo una opción en el menú</h2><ol><li>Confirma que tu usuario tenga el permiso necesario.</li><li>Verifica si la función está incluida en tu plan.</li><li>Cierra sesión y vuelve a entrar si te acaban de cambiar permisos.</li></ol>
<h2>No encuentro un producto</h2><ol><li>Busca por nombre y código.</li><li>Confirma el almacén.</li><li>Revisa que el producto esté activo.</li><li>Si usa variantes, selecciona la variante correcta.</li></ol>
<h2>El POS no me deja vender</h2><ol><li>Comprueba que tengas una caja física asignada.</li><li>Confirma que la caja esté abierta.</li><li>Verifica que no esté abierta por otro usuario.</li><li>Revisa la existencia del producto.</li></ol>
<h2>La factura SAR no tiene los datos correctos</h2><ol><li>Revisa los datos fiscales del emisor.</li><li>Revisa el punto de emisión.</li><li>Confirma CAI y rango activo.</li><li>Comprueba la fecha límite de emisión.</li></ol>
<h2>La página parece tener datos antiguos</h2><p>Actualiza la página. Si se acaba de desplegar una actualización y el navegador conserva archivos anteriores, realiza una recarga completa del sitio.</p>
<div class="manual-note">Si después de estas comprobaciones el problema continúa, crea un ticket e indica cuáles pasos ya revisaste.</div>
HTML,
            ],
            [
                'category' => 'prodex-ayuda-cuenta',
                'title' => 'Buenas prácticas para trabajar con PRODEX sin perder trazabilidad',
                'slug' => 'prodex-buenas-practicas-operacion',
                'sort_order' => 30,
                'content' => <<<'HTML'
<div class="manual-intro">Un ERP funciona mejor cuando cada operación real se registra con el documento adecuado.</div>
<h2>Hábitos recomendados</h2><ul class="manual-checklist"><li>Cada empleado usa su propia cuenta.</li><li>Las cajas se abren y cierran por turno.</li><li>Compras reales se registran como compras.</li><li>Devoluciones se registran como devoluciones.</li><li>Movimientos entre almacenes se registran como transferencias.</li><li>Diferencias físicas se investigan antes de hacer ajustes.</li><li>No se eliminan documentos solo para “hacer cuadrar” reportes.</li><li>Se revisan alertas de stock y reportes periódicamente.</li></ul>
<h2>Por qué importa</h2><p>Cuando se utilizan los flujos correctos, inventario, ventas, compras, caja y reportes cuentan la misma historia. Eso hace que PRODEX sea una herramienta para tomar decisiones, no solo un lugar donde guardar datos.</p>
HTML,
            ],
        ];

        foreach ($articles as $article) {
            if ($db->table('kb_articles')->where('slug', $article['slug'])->exists()) {
                continue;
            }

            $db->table('kb_articles')->insert([
                'kb_category_id' => $categoryIds[$article['category']],
                'title' => $article['title'],
                'slug' => $article['slug'],
                'content' => $article['content'],
                'is_published' => true,
                'sort_order' => $article['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally left in place. Manual content can be edited by the
        // SuperAdmin after deployment, so an automatic rollback must not
        // delete or overwrite those edits.
    }
};
