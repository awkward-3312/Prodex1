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
                'description' => 'Guías para estructurar sucursales, bodegas, empleados, usuarios y permisos en PRODEX.',
                'icon' => 'building-2',
                'sort_order' => 20,
            ]
        );

        KbArticle::updateOrCreate(
            ['slug' => 'sucursales-y-bodegas'],
            [
                'kb_category_id' => $organization->id,
                'title' => 'Sucursales y bodegas en PRODEX',
                'content' => $this->branchesWarehousesContent(),
                'is_published' => true,
                'sort_order' => 10,
            ]
        );

        KbArticle::updateOrCreate(
            ['slug' => 'empleados-usuarios-puestos-permisos'],
            [
                'kb_category_id' => $organization->id,
                'title' => 'Empleados, usuarios, puestos y permisos',
                'content' => $this->employeeAccessContent(),
                'is_published' => true,
                'sort_order' => 20,
            ]
        );
    }

    private function branchesWarehousesContent(): string
    {
        return <<<'HTML'
<h2>Para qué sirve</h2>
<p>Una <strong>sucursal</strong> representa una ubicación o unidad operativa de la empresa. Una <strong>bodega</strong> representa un lugar donde PRODEX controla existencias. Una sucursal puede tener una o varias bodegas.</p>
<p>Ejemplo:</p>
<ul>
  <li>Sucursal Mall
    <ul>
      <li>Bodega de venta</li>
      <li>Bodega trasera</li>
      <li>Bodega de cuarentena</li>
    </ul>
  </li>
  <li>Centro de distribución
    <ul>
      <li>Bodega principal</li>
    </ul>
  </li>
</ul>
<h2>Orden recomendado de configuración</h2>
<ol>
  <li>Cree primero la sucursal.</li>
  <li>Complete su código, ubicación y responsable.</li>
  <li>Cree o asigne las bodegas que pertenecen a esa sucursal.</li>
  <li>Defina una bodega predeterminada si la sucursal tiene más de una.</li>
  <li>En Gestión de personal, asigne cada empleado a su sucursal.</li>
  <li>En Usuarios y accesos, configure el rol y las bodegas que ese usuario puede operar.</li>
</ol>
<h2>Regla importante</h2>
<p>El <strong>rol</strong> define qué puede hacer una persona. La <strong>sucursal o bodega asignada</strong> define dónde puede hacerlo. Tener permiso de inventario no concede acceso automático al inventario de todas las sucursales.</p>
<h2>Bodegas y stock</h2>
<p>Cada bodega mantiene su propio inventario. Mover existencias entre bodegas debe realizarse mediante una transferencia de stock; no edite cantidades manualmente para simular un traslado.</p>
<h2>Recomendación</h2>
<p>No elimine una sucursal o bodega que ya tenga historial. Desactívela para conservar trazabilidad de ventas, movimientos, transferencias y auditorías.</p>
HTML;
    }

    private function employeeAccessContent(): string
    {
        return <<<'HTML'
<h2>Conceptos</h2>
<p>PRODEX separa cuatro responsabilidades:</p>
<ul>
  <li><strong>Empleado:</strong> la persona dentro de Gestión de personal.</li>
  <li><strong>Puesto laboral:</strong> su función organizacional, por ejemplo Cajero o Bodeguero.</li>
  <li><strong>Usuario:</strong> la cuenta con la que entra a PRODEX.</li>
  <li><strong>Rol y permisos:</strong> las acciones que esa cuenta puede realizar.</li>
</ul>
<p>El puesto no sustituye al rol. PRODEX puede sugerir un rol según el puesto, pero el administrador decide los permisos finales.</p>
<h2>Flujo recomendado</h2>
<ol>
  <li>Cree el empleado desde Gestión de personal.</li>
  <li>Asigne empresa, departamento, sucursal, puesto y turno.</li>
  <li>Si la persona necesita entrar a PRODEX, cree o vincule su usuario.</li>
  <li>Desde Usuarios y accesos asigne el rol.</li>
  <li>Defina sus bodegas operativas y su bodega predeterminada.</li>
  <li>Active únicamente los permisos necesarios para su trabajo.</li>
</ol>
<h2>Puestos predeterminados</h2>
<p>PRODEX ofrece plantillas comunes como Gerente de sucursal, Administrador de sucursal, Cajero, Supervisor de caja, Servicio al cliente, Bodeguero, Encargado de inventario, Encargado de recepción, Vendedor, Compras, Contabilidad, Recursos Humanos y Mantenimiento. También puede crear puestos personalizados.</p>
<h2>Principio de mínimo acceso</h2>
<ul>
  <li>Un cajero puede consultar inventario de su sucursal, pero no ajustar existencias ni eliminar productos.</li>
  <li>Un bodeguero puede registrar movimientos, conteos y recepciones en sus bodegas asignadas.</li>
  <li>Un gerente puede supervisar la sucursal sin recibir automáticamente permisos administrativos globales.</li>
</ul>
<h2>Alcance</h2>
<p>Los permisos responden <strong>qué puede hacer</strong> el usuario. Las sucursales y bodegas responden <strong>sobre qué datos puede hacerlo</strong>.</p>
HTML;
    }
}
