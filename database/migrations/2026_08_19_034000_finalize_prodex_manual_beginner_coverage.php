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
            'prodex-como-orientarte-en-el-sistema' => <<<'HTML'
<div class="manual-intro"><strong>Si es tu primer día en PRODEX, empieza aquí.</strong> No necesitas aprender todo el sistema de una vez. PRODEX está dividido por áreas del negocio; primero identifica qué quieres hacer y después entra al módulo correspondiente.</div>
<h2>La idea más importante</h2><p>Piensa en PRODEX como varias áreas de una empresa conectadas entre sí. Un producto que compras puede aumentar inventario; luego puede venderse en POS; esa venta puede afectar caja y aparecer en reportes. Por eso conviene registrar cada operación en el módulo correcto.</p>
<h2>¿Dónde hago cada cosa?</h2><table><thead><tr><th>Quiero...</th><th>Busca...</th></tr></thead><tbody><tr><td>Crear o consultar artículos</td><td>Productos</td></tr><tr><td>Registrar mercadería que compré</td><td>Compras</td></tr><tr><td>Vender a un cliente</td><td>POS / Ventas</td></tr><tr><td>Registrar o consultar clientes/proveedores</td><td>Personas</td></tr><tr><td>Crear empleados con acceso al sistema</td><td>Gestión de usuarios</td></tr><tr><td>Configurar permisos</td><td>Roles / permisos</td></tr><tr><td>Registrar gastos y consultar funciones financieras disponibles</td><td>Contabilidad</td></tr><tr><td>Preparar datos fiscales de Honduras</td><td>Contabilidad → Facturación SAR</td></tr><tr><td>Consultar resultados</td><td>Reportes</td></tr><tr><td>Cambiar parámetros del negocio</td><td>Configuración</td></tr></tbody></table>
<h2>Cómo leer una pantalla sin conocerla</h2><ol><li><strong>Lee el título.</strong> Confirma en qué módulo estás.</li><li><strong>Mira filtros y almacén.</strong> Un filtro puede hacer que parezca que faltan datos.</li><li><strong>Busca la acción principal.</strong> Normalmente será agregar, crear, guardar, pagar, imprimir o similar.</li><li><strong>No confirmes todavía si no entiendes el efecto.</strong> Revisa los datos antes de guardar operaciones reales.</li></ol>
<h2>Palabras que verás con frecuencia</h2><table><thead><tr><th>Palabra</th><th>Qué significa aquí</th></tr></thead><tbody><tr><td>Almacén</td><td>Ubicación donde PRODEX controla existencias.</td></tr><tr><td>Referencia</td><td>Dato que ayuda a identificar una operación o documento.</td></tr><tr><td>Estado</td><td>Situación actual de un registro.</td></tr><tr><td>Rol</td><td>Conjunto de permisos asignado a un tipo de usuario.</td></tr><tr><td>Caja física</td><td>Punto/gaveta de cobro asignado a un cajero.</td></tr><tr><td>Stock</td><td>Existencia registrada de un producto.</td></tr></tbody></table>
<h2>Si una opción no aparece</h2><p>No asumas que el sistema está dañado. Primero comprueba permisos, almacén, configuración previa y funciones disponibles para tu cuenta. Dos usuarios pueden ver menús diferentes porque tienen responsabilidades distintas.</p>
<div class="manual-note"><strong>Forma rápida de aprender:</strong> busca en este manual la acción concreta que quieres hacer: “crear producto”, “abrir caja”, “registrar compra”, “CAI” o “crear usuario”.</div>
HTML,
            'prodex-categorias-marcas-unidades' => <<<'HTML'
<div class="manual-intro"><strong>Antes de cargar muchos productos, organiza estas tres piezas.</strong> Categoría responde “¿qué tipo de producto es?”, marca responde “¿de qué marca/fabricante es?” y unidad responde “¿cómo lo cuento o mido?”.</div>
<h2>1. Categorías</h2><p>Sirven para agrupar productos parecidos. Por ejemplo, una tienda puede tener Bebidas, Limpieza y Snacks.</p><div class="manual-note"><strong>Ejemplo:</strong> “Coca-Cola 500 ml” puede pertenecer a la categoría Bebidas. La categoría no debería ser “Coca-Cola 500 ml”; ese es el producto.</div>
<h3>Cómo decidir una categoría</h3><ol><li>Piensa cómo buscarías el producto en un reporte.</li><li>Usa nombres amplios pero útiles.</li><li>Busca si ya existe antes de crear otra.</li></ol><p>Evita terminar con “Bebida”, “Bebidas”, “bebidas” y “Refrescos bebidas” si representan lo mismo.</p>
<h2>2. Marcas</h2><p>La marca identifica una línea comercial o fabricante cuando ese dato es útil para tu negocio. Te permite buscar y analizar productos agrupados por marca.</p><div class="manual-note"><strong>Ejemplo:</strong> Categoría = Bebidas; Marca = una marca comercial; Producto = la presentación específica que vendes.</div>
<h2>3. Unidades</h2><p>La unidad indica cómo manejas cantidades: unidad, caja, kilogramo, litro, metro, etc. Elegirla mal puede provocar confusión en compras y ventas.</p>
<h3>Cuando compras y vendes diferente</h3><p>Supón que compras una caja que contiene varias unidades y luego vendes las unidades individualmente. Debes revisar cuidadosamente la configuración de unidades/conversión disponible para que una compra no termine representando una cantidad equivocada.</p>
<h2>Orden recomendado para un negocio nuevo</h2><div class="manual-step"><span class="manual-step-number">1</span><strong>Haz una lista corta de categorías.</strong></div><div class="manual-step"><span class="manual-step-number">2</span><strong>Crea solo las marcas que realmente utilizarás.</strong></div><div class="manual-step"><span class="manual-step-number">3</span><strong>Define las unidades necesarias.</strong></div><div class="manual-step"><span class="manual-step-number">4</span><strong>Crea un producto de prueba y comprueba que la clasificación tiene sentido.</strong></div><div class="manual-step"><span class="manual-step-number">5</span><strong>Después carga el resto.</strong></div>
<div class="manual-warning"><strong>No improvises la estructura mientras importas cientos de productos.</strong> Corregir categorías y unidades después puede ser mucho más trabajoso.</div>
HTML,
        ];

        foreach ($guides as $slug => $content) {
            $db->table('kb_articles')->where('slug', $slug)->update(['content' => $content, 'updated_at' => $now]);
        }

        $categoryId = $db->table('kb_categories')->where('slug', 'prodex-primeros-pasos')->value('id');
        if ($categoryId && ! $db->table('kb_articles')->where('slug', 'prodex-glosario-principiantes')->exists()) {
            $db->table('kb_articles')->insert([
                'kb_category_id' => $categoryId,
                'title' => 'Glosario PRODEX para principiantes',
                'slug' => 'prodex-glosario-principiantes',
                'content' => <<<'HTML'
<div class="manual-intro"><strong>No necesitas conocer términos de ERP para usar PRODEX.</strong> Este glosario explica palabras frecuentes con lenguaje sencillo.</div>
<table><thead><tr><th>Término</th><th>Explicación</th></tr></thead><tbody>
<tr><td>ERP</td><td>Sistema que conecta diferentes áreas del negocio, como ventas, inventario, compras y reportes.</td></tr>
<tr><td>POS</td><td>Punto de venta: pantalla utilizada para registrar y cobrar ventas.</td></tr>
<tr><td>Stock / existencia</td><td>Cantidad de producto que el sistema tiene registrada.</td></tr>
<tr><td>Almacén</td><td>Ubicación donde se controla inventario; puede representar bodega, tienda o sucursal según tu organización.</td></tr>
<tr><td>SKU / código</td><td>Identificador que ayuda a reconocer un producto.</td></tr>
<tr><td>Variante</td><td>Versión de un mismo producto, por ejemplo una talla o color.</td></tr>
<tr><td>Costo</td><td>Valor asociado a lo que cuesta adquirir el producto según tu registro.</td></tr>
<tr><td>Precio</td><td>Valor al que se ofrece/vende el producto.</td></tr>
<tr><td>Proveedor</td><td>Persona o empresa de quien compras.</td></tr>
<tr><td>Cliente</td><td>Persona o empresa a quien vendes.</td></tr>
<tr><td>Compra</td><td>Registro de mercadería adquirida a un proveedor.</td></tr>
<tr><td>Venta</td><td>Registro de productos/servicios vendidos a un cliente.</td></tr>
<tr><td>Transferencia</td><td>Movimiento de inventario entre almacenes.</td></tr>
<tr><td>Ajuste</td><td>Corrección controlada de una diferencia de inventario.</td></tr>
<tr><td>Devolución</td><td>Registro que refleja que una operación se revierte total o parcialmente mediante el flujo correspondiente.</td></tr>
<tr><td>Caja física</td><td>Punto o gaveta real de cobro asociada a la operación de un cajero.</td></tr>
<tr><td>Apertura de caja</td><td>Inicio de un turno indicando el saldo inicial.</td></tr>
<tr><td>Cierre de caja</td><td>Final del turno donde se compara el conteo con lo esperado.</td></tr>
<tr><td>Rol</td><td>Perfil de acceso que agrupa permisos.</td></tr>
<tr><td>Permiso</td><td>Autorización para ver o realizar una acción dentro del sistema.</td></tr>
<tr><td>RTN</td><td>Identificación tributaria utilizada en Honduras.</td></tr>
<tr><td>CAI</td><td>Código de autorización relacionado con la documentación fiscal autorizada.</td></tr>
<tr><td>Rango autorizado</td><td>Numeración fiscal que puede utilizarse dentro de los límites y fechas correspondientes.</td></tr>
<tr><td>ISV</td><td>Impuesto sobre Ventas; su aplicación depende de la operación/producto y configuración fiscal correspondiente.</td></tr>
<tr><td>Tenant / espacio de trabajo</td><td>Entorno separado de una empresa dentro de la plataforma PRODEX.</td></tr>
</tbody></table>
<div class="manual-note">Si un término fiscal determina cómo debes facturar, utiliza este glosario solo para orientarte y confirma el tratamiento aplicable con la documentación o responsable fiscal de tu empresa.</div>
HTML,
                'is_published' => true,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Preserve manual content because SuperAdmin can edit it after deployment.
    }
};
