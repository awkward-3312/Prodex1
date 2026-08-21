<?php

namespace Database\Seeders\Central;

use App\Models\Central\KbArticle;
use App\Models\Central\KbCategory;
use Illuminate\Database\Seeder;

class ProdexManualsSeeder extends Seeder
{
    public function run(): void
    {
        $organization = KbCategory::updateOrCreate(
            ['slug' => 'organizacion-y-accesos'],
            [
                'name' => 'Organización y accesos',
                'description' => 'Guías para estructurar sucursales, usuarios, roles, puestos y alcance operativo en PRODEX.',
                'icon' => 'building-2',
                'sort_order' => 20,
            ]
        );

        $inventory = KbCategory::updateOrCreate(
            ['slug' => 'inventario-y-logistica'],
            [
                'name' => 'Inventario y logística',
                'description' => 'Guías para ubicaciones de inventario, almacenes/CD, movimientos, transferencias y recepción.',
                'icon' => 'warehouse',
                'sort_order' => 30,
            ]
        );

        KbArticle::updateOrCreate(
            ['slug' => 'sucursales-y-bodegas'],
            [
                'kb_category_id' => $organization->id,
                'title' => 'Sucursales, ubicaciones de inventario y almacenes/CD',
                'content' => $this->branchesWarehousesContent(),
                'is_published' => true,
                'sort_order' => 10,
            ]
        );

        KbArticle::updateOrCreate(
            ['slug' => 'empleados-usuarios-puestos-permisos'],
            [
                'kb_category_id' => $organization->id,
                'title' => 'Usuarios, empleados, puestos, roles y permisos',
                'content' => $this->employeeAccessContent(),
                'is_published' => true,
                'sort_order' => 20,
            ]
        );

        KbArticle::updateOrCreate(
            ['slug' => 'transferencias-stock-entre-sucursales'],
            [
                'kb_category_id' => $inventory->id,
                'title' => 'Cómo transferir stock entre ubicaciones',
                'content' => $this->stockTransferContent(),
                'is_published' => true,
                'sort_order' => 10,
            ]
        );

        KbArticle::updateOrCreate(
            ['slug' => 'recibir-transferencias-stock-qr'],
            [
                'kb_category_id' => $inventory->id,
                'title' => 'Cómo recibir una transferencia de stock',
                'content' => $this->stockReceivingContent(),
                'is_published' => true,
                'sort_order' => 20,
            ]
        );

        KbArticle::updateOrCreate(
            ['slug' => 'faltantes-defectuosos-transferencias'],
            [
                'kb_category_id' => $inventory->id,
                'title' => 'Faltantes y productos defectuosos en transferencias',
                'content' => $this->transferIssuesContent(),
                'is_published' => true,
                'sort_order' => 30,
            ]
        );
    }

    private function branchesWarehousesContent(): string
    {
        return <<<'HTML'
<h2>Tres conceptos diferentes</h2>
<p>PRODEX separa la estructura comercial de la estructura logística y del lugar exacto donde existe cada producto:</p>
<ul>
  <li><strong>Sucursal:</strong> lugar donde opera la empresa: tienda, oficina o punto de atención.</li>
  <li><strong>Almacén / Centro de distribución (CD):</strong> instalación logística dedicada que abastece a una o varias sucursales.</li>
  <li><strong>Ubicación de inventario:</strong> lugar exacto donde se mantiene stock, por ejemplo Piso de venta, Bodega de sucursal, Cuarentena, Dañados o Devoluciones.</li>
</ul>
<p>Una sucursal no consume un almacén/CD adicional solo por manejar inventario.</p>

<h2>Ejemplo</h2>
<ul>
  <li>CD Principal
    <ul><li>Inventario principal</li></ul>
  </li>
  <li>Sucursal Mall
    <ul>
      <li>Piso de venta</li>
      <li>Bodega de sucursal</li>
      <li>Cuarentena</li>
    </ul>
  </li>
  <li>Sucursal Centro
    <ul>
      <li>Piso de venta</li>
      <li>Bodega de sucursal</li>
    </ul>
  </li>
</ul>
<p>En este ejemplo la empresa tiene <strong>un CD</strong>, dos sucursales y cinco ubicaciones internas de inventario.</p>

<h2>Crear una sucursal</h2>
<ol>
  <li>Abra Organización → Sucursales.</li>
  <li>Indique nombre, código, dirección y responsable.</li>
  <li>Active <strong>Esta sucursal maneja inventario</strong> si corresponde.</li>
  <li>PRODEX crea el <strong>Piso de venta</strong> como ubicación predeterminada.</li>
  <li>Si la sucursal guarda mercancía adicional, puede crear también <strong>Bodega de sucursal</strong>.</li>
  <li>Agregue después Cuarentena, Dañados u otras ubicaciones cuando la operación lo requiera.</li>
</ol>

<h2>Almacenes/CD</h2>
<p>Use Almacenes/CD para instalaciones logísticas dedicadas. No cree un almacén nuevo únicamente para representar cada sucursal o cada trastienda.</p>

<h2>Regla de inventario</h2>
<p>El producto debe moverse mediante operaciones trazables entre ubicaciones. No modifique cantidades directamente para simular un traslado.</p>
HTML;
    }

    private function employeeAccessContent(): string
    {
        return <<<'HTML'
<h2>La seguridad de PRODEX no depende de Gestión de personal</h2>
<p>Toda empresa puede crear usuarios, asignar roles y limitar su alcance aunque su plan no incluya Gestión de personal.</p>

<h2>Conceptos</h2>
<ul>
  <li><strong>Usuario:</strong> cuenta que inicia sesión en PRODEX.</li>
  <li><strong>Rol:</strong> determina qué acciones puede ejecutar.</li>
  <li><strong>Sucursal y ubicaciones asignadas:</strong> determinan dónde puede ejecutar esas acciones.</li>
  <li><strong>Empleado:</strong> persona registrada en Gestión de personal; es opcional para efectos de acceso.</li>
  <li><strong>Puesto:</strong> función laboral como Cajero o Bodeguero. Puede sugerir un rol, pero nunca concede permisos automáticamente.</li>
</ul>

<h2>Empresa sin Gestión de personal</h2>
<ol>
  <li>Abra Usuarios y accesos → Usuarios.</li>
  <li>Seleccione Crear usuario.</li>
  <li>Asigne un rol.</li>
  <li>Seleccione una o varias sucursales.</li>
  <li>Seleccione las ubicaciones de inventario que puede operar.</li>
  <li>Defina la sucursal y ubicación predeterminadas.</li>
</ol>

<h2>Empresa con Gestión de personal</h2>
<ol>
  <li>Cree el empleado y asigne sucursal, departamento y puesto.</li>
  <li>Abra Usuarios y accesos → Acceso de empleados.</li>
  <li>Cree o vincule su cuenta.</li>
  <li>Seleccione el rol y el alcance operativo.</li>
</ol>

<h2>Ejemplos de alcance</h2>
<ul>
  <li><strong>Cajero:</strong> normalmente una sucursal y su Piso de venta.</li>
  <li><strong>Bodeguero:</strong> puede necesitar Piso de venta, Bodega de sucursal y Cuarentena.</li>
  <li><strong>Gerente:</strong> puede tener alcance a toda su sucursal, pero solo las acciones habilitadas por su rol.</li>
  <li><strong>Supervisor regional:</strong> puede utilizar un mismo usuario para varias sucursales.</li>
</ul>

<h2>Traslado temporal de personal</h2>
<p>Cuando una persona cubra otra tienda temporalmente, PRODEX puede aplicar una asignación operativa temporal con la sucursal, ubicación de inventario y caja correspondientes. Al finalizar, vuelve a su configuración habitual.</p>

<h2>Regla principal</h2>
<p><strong>Rol = qué puede hacer. Alcance = dónde puede hacerlo.</strong></p>
HTML;
    }

    private function stockTransferContent(): string
    {
        return <<<'HTML'
<h2>Qué es una transferencia</h2>
<p>Una transferencia logística mueve mercancía físicamente entre dos ubicaciones de inventario que requieren despacho y recepción.</p>
<p>Ejemplos:</p>
<ul>
  <li>CD Principal → Bodega de Sucursal Mall.</li>
  <li>Sucursal Centro → Sucursal Mall.</li>
</ul>

<h2>Movimiento interno</h2>
<p>Cuando la mercancía se mueve dentro de una misma sucursal, por ejemplo <strong>Bodega de sucursal → Piso de venta</strong>, PRODEX puede manejarlo como un movimiento interno sin necesidad de simular otro almacén/CD.</p>

<h2>Transferencia logística</h2>
<ol>
  <li>Seleccione la ubicación de origen y la ubicación destino.</li>
  <li>Agregue los productos y cantidades.</li>
  <li>Complete la aprobación requerida por su empresa.</li>
  <li>Despache el envío.</li>
  <li>El stock queda en tránsito.</li>
  <li>El receptor verifica físicamente y confirma la recepción.</li>
</ol>

<h2>Trazabilidad</h2>
<p>No utilice ajustes manuales para representar mercancía viajando entre ubicaciones. La transferencia conserva origen, destino, usuario, cantidades, incidencias y recepción.</p>
HTML;
    }

    private function stockReceivingContent(): string
    {
        return <<<'HTML'
<h2>Quién puede recibir</h2>
<p>Solo un usuario cuyo rol incluya recepción y cuyo alcance incluya la ubicación destino puede confirmar la mercancía.</p>

<h2>Ver mercancía en camino</h2>
<p>Abra Operaciones → Ingreso de stock. Las transferencias destinadas a ubicaciones autorizadas aparecen como pendientes de recibir.</p>

<h2>Recepción</h2>
<ol>
  <li>Abra la transferencia o escanee su código QR.</li>
  <li>Compare físicamente cada producto.</li>
  <li>Registre cantidades correctas, faltantes o defectuosas.</li>
  <li>Confirme la recepción.</li>
</ol>

<h2>Cuándo aumenta el inventario</h2>
<p>La ubicación destino recibe únicamente la cantidad confirmada como correcta. Mientras el envío está en tránsito no debe aparecer como existencia disponible del destino.</p>
HTML;
    }

    private function transferIssuesContent(): string
    {
        return <<<'HTML'
<h2>Mercancía faltante</h2>
<p>Registre como faltante lo que no llegó físicamente. No incremente el inventario destino por unidades ausentes.</p>

<h2>Mercancía defectuosa</h2>
<p>Registre las unidades defectuosas. Deben mantenerse fuera del inventario vendible y, cuando corresponda, enviarse a una ubicación de Cuarentena o Dañados.</p>

<h2>Recepciones parciales</h2>
<p>Registre únicamente las cantidades recibidas. La transferencia conserva lo pendiente hasta su recepción o resolución.</p>

<h2>Auditoría</h2>
<p>No corrija una discrepancia editando directamente el stock. Utilice el flujo de incidencia para conservar quién recibió, qué faltó, qué llegó defectuoso y cómo se resolvió.</p>
HTML;
    }
}
