<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;

class RoleTemplateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless((int) $user->role_id === 1 || $user->hasPermissionName('permissions_edit'), 403);

        $existingPermissions = Permission::query()->pluck('name')->flip();

        $templates = collect($this->templates())->map(function (array $template) use ($existingPermissions) {
            $template['permissions'] = collect($template['permissions'])
                ->filter(fn ($permission) => $existingPermissions->has($permission))
                ->values()
                ->all();
            return $template;
        })->values();

        return response()->json([
            'templates' => $templates,
            'note' => 'Las plantillas son puntos de partida. El administrador puede agregar o quitar permisos antes de guardar el rol.',
        ]);
    }

    private function templates(): array
    {
        return [
            [
                'key' => 'cashier',
                'name' => 'Cajero',
                'description' => 'Opera POS y consulta información necesaria para vender dentro de su alcance.',
                'permissions' => ['dashboard', 'Pos_view', 'Sales_view', 'Sales_add', 'payment_sales_view', 'payment_sales_add', 'Customers_view', 'Customers_add', 'products_view'],
            ],
            [
                'key' => 'cash_supervisor',
                'name' => 'Supervisor de caja',
                'description' => 'Supervisa ventas, pagos y cierres sin recibir permisos administrativos globales.',
                'permissions' => ['dashboard', 'Pos_view', 'Sales_view', 'Sales_add', 'Sales_edit', 'payment_sales_view', 'payment_sales_add', 'payment_sales_edit', 'Customers_view', 'products_view', 'Reports_sales', 'Reports_payments_Sales'],
            ],
            [
                'key' => 'warehouse_operator',
                'name' => 'Bodeguero',
                'description' => 'Consulta inventario y ejecuta movimientos físicos autorizados en bodegas asignadas.',
                'permissions' => ['dashboard', 'products_view', 'transfer_view', 'transfer_add', 'transfer_receive', 'count_stock', 'stock_report', 'Warehouse_report'],
            ],
            [
                'key' => 'receiving_clerk',
                'name' => 'Encargado de recepción',
                'description' => 'Recibe compras y transferencias destinadas a su bodega y registra diferencias de recepción.',
                'permissions' => ['dashboard', 'products_view', 'Purchases_view', 'transfer_view', 'transfer_receive', 'stock_report'],
            ],
            [
                'key' => 'inventory_manager',
                'name' => 'Encargado de inventario',
                'description' => 'Gestiona existencias, conteos, ajustes, transferencias y sus incidencias dentro de su alcance.',
                'permissions' => ['dashboard', 'products_view', 'products_add', 'products_edit', 'transfer_view', 'transfer_add', 'transfer_edit', 'transfer_receive', 'transfer_issue_manage', 'adjustment_view', 'adjustment_add', 'adjustment_edit', 'count_stock', 'stock_report', 'Warehouse_report', 'inventory_valuation'],
            ],
            [
                'key' => 'sales_rep',
                'name' => 'Vendedor',
                'description' => 'Gestiona clientes, cotizaciones y ventas según su alcance.',
                'permissions' => ['dashboard', 'Sales_view', 'Sales_add', 'Quotations_view', 'Quotations_add', 'Customers_view', 'Customers_add', 'products_view'],
            ],
            [
                'key' => 'sales_supervisor',
                'name' => 'Supervisor de ventas',
                'description' => 'Supervisa ventas y resultados comerciales de su alcance.',
                'permissions' => ['dashboard', 'Sales_view', 'Sales_add', 'Sales_edit', 'Quotations_view', 'Quotations_add', 'Quotations_edit', 'Customers_view', 'Customers_add', 'Customers_edit', 'products_view', 'Reports_sales', 'Top_products', 'Top_customers'],
            ],
            [
                'key' => 'purchasing',
                'name' => 'Compras',
                'description' => 'Gestiona compras, proveedores y devoluciones de compra.',
                'permissions' => ['dashboard', 'Purchases_view', 'Purchases_add', 'Purchases_edit', 'Suppliers_view', 'Suppliers_add', 'Suppliers_edit', 'Purchase_Returns_view', 'Purchase_Returns_add', 'products_view', 'Reports_purchase'],
            ],
            [
                'key' => 'accounting',
                'name' => 'Contabilidad',
                'description' => 'Gestiona cuentas, gastos, depósitos y reportes financieros autorizados.',
                'permissions' => ['dashboard', 'account', 'expense_view', 'expense_add', 'expense_edit', 'deposit_view', 'deposit_add', 'deposit_edit', 'transfer_money', 'Reports_profit', 'expenses_report', 'deposits_report', 'report_transactions'],
            ],
            [
                'key' => 'human_resources',
                'name' => 'Recursos Humanos',
                'description' => 'Gestiona empleados, asistencia, vacaciones, turnos y planilla.',
                'permissions' => ['dashboard', 'view_employee', 'add_employee', 'edit_employee', 'department', 'designation', 'office_shift', 'attendance', 'leave', 'holiday', 'payroll'],
            ],
            [
                'key' => 'branch_manager',
                'name' => 'Gerente de sucursal',
                'description' => 'Supervisa la operación de una sucursal sin convertirse en administrador global.',
                'permissions' => ['dashboard', 'Sales_view', 'Purchases_view', 'products_view', 'Customers_view', 'Suppliers_view', 'transfer_view', 'transfer_issue_manage', 'stock_report', 'Warehouse_report', 'Reports_sales', 'Reports_purchase', 'Reports_profit', 'view_employee', 'attendance', 'branches_view'],
            ],
            [
                'key' => 'branch_admin',
                'name' => 'Administrador de sucursal',
                'description' => 'Administra la operación diaria de la sucursal con alcance limitado.',
                'permissions' => ['dashboard', 'Sales_view', 'Sales_add', 'Purchases_view', 'Purchases_add', 'products_view', 'products_edit', 'Customers_view', 'Customers_add', 'Suppliers_view', 'Suppliers_add', 'transfer_view', 'transfer_add', 'transfer_receive', 'transfer_issue_manage', 'stock_report', 'view_employee', 'attendance', 'branches_view'],
            ],
            [
                'key' => 'customer_service',
                'name' => 'Servicio al cliente',
                'description' => 'Consulta clientes, ventas y productos para atender solicitudes.',
                'permissions' => ['dashboard', 'Customers_view', 'Customers_add', 'Customers_edit', 'Sales_view', 'products_view', 'Quotations_view'],
            ],
            [
                'key' => 'maintenance',
                'name' => 'Mantenimiento',
                'description' => 'Acceso operativo enfocado en tareas y mantenimiento.',
                'permissions' => ['dashboard', 'tasks'],
            ],
            [
                'key' => 'driver',
                'name' => 'Motorista / Repartidor',
                'description' => 'Acceso mínimo para consultar traslados y entregas asignadas.',
                'permissions' => ['dashboard', 'transfer_view', 'shipment'],
            ],
            [
                'key' => 'security',
                'name' => 'Seguridad',
                'description' => 'Acceso mínimo; no recibe permisos comerciales ni de inventario por defecto.',
                'permissions' => ['dashboard'],
            ],
        ];
    }
}
