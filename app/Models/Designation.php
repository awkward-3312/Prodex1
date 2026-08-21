<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Designation extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'designation', 'code', 'description', 'is_system_default', 'is_active',
        'suggested_role_key', 'department_id', 'company_id',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'company_id' => 'integer',
        'is_system_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function defaultTemplates(): array
    {
        return [
            ['code' => 'branch_manager', 'name' => 'Gerente de sucursal', 'role' => 'branch_manager', 'description' => 'Supervisa la operación general de una sucursal.'],
            ['code' => 'branch_admin', 'name' => 'Administrador de sucursal', 'role' => 'branch_admin', 'description' => 'Apoya la administración operativa de la sucursal.'],
            ['code' => 'cashier', 'name' => 'Cajero', 'role' => 'cashier', 'description' => 'Opera punto de venta y caja dentro de su sucursal.'],
            ['code' => 'cash_supervisor', 'name' => 'Supervisor de caja', 'role' => 'cash_supervisor', 'description' => 'Supervisa cajas, cierres y operaciones de cajeros.'],
            ['code' => 'customer_service', 'name' => 'Servicio al cliente', 'role' => 'customer_service', 'description' => 'Atiende clientes y consulta información operativa autorizada.'],
            ['code' => 'warehouse_operator', 'name' => 'Bodeguero', 'role' => 'warehouse_operator', 'description' => 'Gestiona movimientos físicos de inventario en bodegas asignadas.'],
            ['code' => 'inventory_manager', 'name' => 'Encargado de inventario', 'role' => 'inventory_manager', 'description' => 'Supervisa existencias, conteos, ajustes y movimientos.'],
            ['code' => 'receiving_clerk', 'name' => 'Encargado de recepción', 'role' => 'receiving_clerk', 'description' => 'Recibe compras y transferencias destinadas a su bodega.'],
            ['code' => 'sales_rep', 'name' => 'Vendedor', 'role' => 'sales_rep', 'description' => 'Gestiona ventas y clientes según el alcance asignado.'],
            ['code' => 'sales_supervisor', 'name' => 'Supervisor de ventas', 'role' => 'sales_supervisor', 'description' => 'Supervisa vendedores y resultados de su alcance.'],
            ['code' => 'purchasing', 'name' => 'Compras', 'role' => 'purchasing', 'description' => 'Gestiona compras y proveedores.'],
            ['code' => 'accounting', 'name' => 'Contabilidad', 'role' => 'accounting', 'description' => 'Gestiona procesos contables y financieros autorizados.'],
            ['code' => 'human_resources', 'name' => 'Recursos Humanos', 'role' => 'human_resources', 'description' => 'Gestiona empleados y procesos de personal.'],
            ['code' => 'maintenance', 'name' => 'Mantenimiento', 'role' => 'maintenance', 'description' => 'Gestiona tareas y servicios de mantenimiento.'],
            ['code' => 'driver', 'name' => 'Motorista / Repartidor', 'role' => 'driver', 'description' => 'Realiza traslados o entregas físicas.'],
            ['code' => 'security', 'name' => 'Seguridad', 'role' => 'security', 'description' => 'Personal de seguridad con acceso operativo limitado.'],
        ];
    }

    public function company()
    {
        return $this->hasOne('App\Models\Company', 'id', 'company_id');
    }

    public function department()
    {
        return $this->hasOne('App\Models\Department', 'id', 'department_id');
    }
}
