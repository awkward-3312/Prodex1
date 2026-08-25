<?php

namespace App\Services;

use App\Models\Permission;
use Illuminate\Validation\ValidationException;

class PermissionCatalogService
{
    public function catalog(): array
    {
        $names = Permission::query()->orderBy('name')->pluck('name')->values();
        $known = $names->flip();
        $moduleOrder = array_flip($this->moduleOrder());

        $described = $names->map(fn (string $name) => $this->describe($name, $known->all()));
        $labels = $described->pluck('label', 'name')->all();

        $groups = $described
            ->map(function (array $permission) use ($labels) {
                $permission['dependency_labels'] = collect($permission['dependencies'])
                    ->map(fn (string $name) => $labels[$name] ?? $this->labelFor($name))
                    ->values()
                    ->all();
                return $permission;
            })
            ->groupBy('module')
            ->map(function ($items, $key) {
                return [
                    'key' => $key,
                    'label' => $this->moduleLabel($key),
                    'description' => $this->moduleDescription($key),
                    'permissions' => $items->sortBy(fn ($item) => [$item['action_order'], $item['label']])->values()->all(),
                ];
            })
            ->sortBy(fn ($group) => $moduleOrder[$group['key']] ?? 999)
            ->values()
            ->all();

        return [
            'groups' => $groups,
            'sensitive' => collect($groups)->flatMap(fn ($group) => $group['permissions'])->where('sensitive', true)->values()->all(),
            'presets' => [
                'read_only' => ['label' => 'Solo lectura', 'description' => 'Permite consultar información sin crear, editar ni eliminar.'],
                'operator' => ['label' => 'Operador', 'description' => 'Permite consultar y registrar operaciones habituales, sin permisos sensibles.'],
                'manager' => ['label' => 'Acceso completo', 'description' => 'Activa todas las operaciones no sensibles disponibles en este módulo.'],
            ],
        ];
    }

    public function normalizeSelection(array $requested): array
    {
        $requested = collect($requested)->map(fn ($name) => trim((string) $name))->filter()->unique()->values();
        $existing = Permission::query()->whereIn('name', $requested)->pluck('name')->values();
        $unknown = $requested->diff($existing)->values();

        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => 'Se enviaron permisos no reconocidos por PRODEX: '.$unknown->implode(', '),
            ]);
        }

        $known = Permission::query()->pluck('name')->flip()->all();
        $expanded = $existing->all();
        foreach ($existing as $permission) {
            foreach ($this->dependenciesFor($permission, $known) as $dependency) $expanded[] = $dependency;
        }
        return array_values(array_unique($expanded));
    }

    private function describe(string $name, array $known): array
    {
        $action = $this->actionFor($name);
        $label = $this->labelFor($name);
        return [
            'name' => $name,
            'label' => $label,
            'description' => $this->descriptionFor($name, $action, $label),
            'module' => $this->moduleFor($name),
            'action' => $action,
            'action_order' => array_search($action, ['view', 'create', 'update', 'delete', 'special'], true),
            'sensitive' => $this->isSensitive($name),
            'dependencies' => $this->dependenciesFor($name, $known),
        ];
    }

    private function dependenciesFor(string $name, array $known): array
    {
        $manual = [
            'transfer_receive' => ['transfer_view'], 'transfer_issue_manage' => ['transfer_view'],
            'batch_manage' => ['batch_view'], 'batch_writeoff' => ['batch_view'], 'batch_force_override' => ['batch_view'],
            'users_add' => ['users_view'], 'users_edit' => ['users_view'], 'users_delete' => ['users_view'],
            'permissions_add' => ['permissions_view'], 'permissions_edit' => ['permissions_view'], 'permissions_delete' => ['permissions_view'],
        ];
        $dependencies = $manual[$name] ?? [];
        foreach (['_add' => '_view', '_edit' => '_view', '_delete' => '_view'] as $suffix => $viewSuffix) {
            if (str_ends_with($name, $suffix)) {
                $candidate = substr($name, 0, -strlen($suffix)).$viewSuffix;
                if (isset($known[$candidate])) $dependencies[] = $candidate;
            }
        }
        return array_values(array_unique(array_filter($dependencies, fn ($dependency) => isset($known[$dependency]))));
    }

    private function moduleFor(string $name): string
    {
        $n = strtolower($name);
        $rules = [
            'access' => ['users_', 'permissions_', 'record_view', 'user_operational_', 'branches_', 'cash_register_override_assignment'],
            'sales' => ['sales_', 'pos_', 'payment_sales', 'sale_returns', 'quotations_', 'customer_display', 'loyalty', 'warranty'],
            'inventory' => ['products_', 'product_', 'barcode', 'category', 'subcategory', 'brand', 'unit', 'count_stock', 'opening_stock', 'inventory_', 'stock_', 'warehouse_report', 'transfer_', 'adjustment_', 'damage_', 'batch_', 'expiry_'],
            'purchases' => ['purchases_', 'purchase_returns', 'payment_purchases', 'suppliers_'],
            'customers' => ['customers_'],
            'hr' => ['employee', 'department', 'designation', 'office_shift', 'attendance', 'leave', 'holiday', 'payroll', 'company'],
            'accounting' => ['account', 'expense_', 'deposit_', 'transfer_money', 'pay_due', 'pay_supplier', 'pay_purchase', 'pay_sale', 'report_transactions'],
            'reports' => ['reports_', 'report_', 'top_', 'users_report', 'seller_report'],
            'settings' => ['setting', 'settings', 'backup', 'currency', 'shipment', 'sms_', 'mail_', 'payment_gateway', 'module_settings', 'appearance_', 'translations_', 'notification_template', 'quickbooks', 'zatca'],
            'projects' => ['project', 'task'],
        ];
        foreach ($rules as $module => $needles) foreach ($needles as $needle) if (str_contains($n, $needle)) return $module;
        return 'other';
    }

    private function actionFor(string $name): string
    {
        $n = strtolower($name);
        if (preg_match('/(^|_)(view|report|reports)(_|$)/', $n) || str_starts_with($n, 'top_')) return 'view';
        if (preg_match('/(_add$|_create$|^add_)/', $n)) return 'create';
        if (preg_match('/(_edit$|_update$|^edit_)/', $n)) return 'update';
        if (preg_match('/(_delete$|_destroy$|writeoff)/', $n)) return 'delete';
        return 'special';
    }

    private function isSensitive(string $name): bool
    {
        $n = strtolower($name);
        foreach (['permissions_', 'users_delete', 'users_edit', 'backup', 'setting_system', 'module_settings', 'payment_gateway', 'cash_register_override_assignment', 'batch_force_override', 'batch_writeoff', 'transfer_issue_manage', 'adjustment_add', 'adjustment_edit', 'adjustment_delete', 'sales_delete', 'purchases_delete', 'payment_', 'transfer_money', 'payroll', 'zatca', 'quickbooks'] as $needle) {
            if (str_contains($n, $needle)) return true;
        }
        return false;
    }

    private function labelFor(string $name): string
    {
        $explicit = [
            'dashboard' => 'Ver tablero principal',
            'Pos_view' => 'Usar punto de venta (POS)',
            'record_view' => 'Ver registros de otros usuarios',
            'Sales_view' => 'Ver ventas', 'Sales_add' => 'Crear ventas', 'Sales_edit' => 'Editar ventas', 'Sales_delete' => 'Eliminar ventas',
            'payment_sales_view' => 'Ver pagos de ventas', 'payment_sales_add' => 'Registrar pagos de ventas', 'payment_sales_edit' => 'Editar pagos de ventas', 'payment_sales_delete' => 'Eliminar pagos de ventas',
            'Quotations_view' => 'Ver cotizaciones', 'Quotations_add' => 'Crear cotizaciones', 'Quotations_edit' => 'Editar cotizaciones', 'Quotations_delete' => 'Eliminar cotizaciones',
            'Sale_Returns_view' => 'Ver devoluciones de ventas', 'Sale_Returns_add' => 'Registrar devoluciones de ventas', 'Sale_Returns_edit' => 'Editar devoluciones de ventas', 'Sale_Returns_delete' => 'Eliminar devoluciones de ventas',
            'customer_loyalty_points_report' => 'Ver reporte de puntos de fidelidad',
            'product_sales_report' => 'Ver reporte de ventas por producto',
            'report_sales_by_brand' => 'Ver reporte de ventas por marca',
            'report_sales_by_category' => 'Ver reporte de ventas por categoría',
            'report_warranty' => 'Ver reporte de garantías',
            'Reports_payments_Sale_Returns' => 'Ver reporte de pagos de devoluciones',
            'products_view' => 'Ver productos', 'products_add' => 'Crear productos', 'products_edit' => 'Editar productos', 'products_delete' => 'Eliminar productos',
            'Purchases_view' => 'Ver compras', 'Purchases_add' => 'Crear compras', 'Purchases_edit' => 'Editar compras', 'Purchases_delete' => 'Eliminar compras',
            'Customers_view' => 'Ver clientes', 'Customers_add' => 'Crear clientes', 'Customers_edit' => 'Editar clientes', 'Customers_delete' => 'Eliminar clientes',
            'Suppliers_view' => 'Ver proveedores', 'Suppliers_add' => 'Crear proveedores', 'Suppliers_edit' => 'Editar proveedores', 'Suppliers_delete' => 'Eliminar proveedores',
            'users_view' => 'Ver usuarios', 'users_add' => 'Crear usuarios', 'users_edit' => 'Editar usuarios', 'users_delete' => 'Eliminar usuarios',
            'permissions_view' => 'Ver roles y permisos', 'permissions_add' => 'Crear roles', 'permissions_edit' => 'Editar roles y permisos', 'permissions_delete' => 'Eliminar roles',
            'transfer_view' => 'Ver transferencias', 'transfer_add' => 'Crear transferencias', 'transfer_edit' => 'Editar transferencias', 'transfer_delete' => 'Eliminar transferencias',
            'transfer_receive' => 'Recibir transferencias', 'transfer_issue_manage' => 'Resolver incidencias de transferencias',
            'adjustment_view' => 'Ver ajustes de inventario', 'adjustment_add' => 'Crear ajustes de inventario', 'adjustment_edit' => 'Editar ajustes de inventario', 'adjustment_delete' => 'Eliminar ajustes de inventario',
            'damage_view' => 'Ver daños de inventario', 'damage_add' => 'Registrar daños de inventario', 'damage_edit' => 'Editar daños de inventario', 'damage_delete' => 'Eliminar daños de inventario',
            'cash_register_override_assignment' => 'Operar fuera de la caja asignada',
            'batch_force_override' => 'Sobrescribir bloqueo por vencimiento', 'batch_writeoff' => 'Dar de baja lotes',
            'setting_system' => 'Administrar configuración general', 'module_settings' => 'Administrar módulos del sistema',
        ];
        if (isset($explicit[$name])) return $explicit[$name];

        $action = $this->actionFor($name);
        $resource = $this->resourceLabel($name);
        if ($action === 'view') return str_contains(strtolower($resource), 'reporte') ? 'Ver '.$resource : 'Ver '.$resource;
        if ($action === 'create') return 'Crear '.$resource;
        if ($action === 'update') return 'Editar '.$resource;
        if ($action === 'delete') return 'Eliminar '.$resource;
        return ucfirst($resource);
    }

    private function resourceLabel(string $name): string
    {
        $resource = preg_replace('/(^add_|^edit_|_view$|_add$|_edit$|_delete$|_create$|_update$|_destroy$)/i', '', $name);
        $resource = preg_replace('/^reports?_/i', 'reporte de ', $resource);
        $resource = preg_replace('/_reports?$/i', ' reporte', $resource);
        $resource = str_replace(['_', '-'], ' ', $resource);

        $phrases = [
            'payment sales' => 'pagos de ventas', 'payment purchases' => 'pagos de compras',
            'sale returns' => 'devoluciones de ventas', 'purchase returns' => 'devoluciones de compras',
            'customer loyalty points' => 'puntos de fidelidad de clientes', 'customer display' => 'pantalla del cliente',
            'office shift' => 'turnos de oficina', 'cash register' => 'cajas', 'transfer money' => 'transferencias de dinero',
            'inventory valuation' => 'valoración de inventario', 'opening stock' => 'inventario inicial', 'count stock' => 'conteo de inventario',
            'stock report' => 'reporte de existencias', 'warehouse report' => 'reporte por almacén',
        ];
        $lower = strtolower(trim($resource));
        foreach ($phrases as $from => $to) $lower = str_replace($from, $to, $lower);

        $words = [
            'sales' => 'ventas', 'sale' => 'venta', 'purchases' => 'compras', 'purchase' => 'compra', 'products' => 'productos', 'product' => 'producto',
            'customers' => 'clientes', 'customer' => 'cliente', 'suppliers' => 'proveedores', 'supplier' => 'proveedor', 'quotations' => 'cotizaciones', 'quotation' => 'cotización',
            'users' => 'usuarios', 'user' => 'usuario', 'permissions' => 'roles y permisos', 'permission' => 'permiso', 'transfers' => 'transferencias', 'transfer' => 'transferencia',
            'adjustments' => 'ajustes de inventario', 'adjustment' => 'ajuste de inventario', 'damages' => 'daños de inventario', 'damage' => 'daño de inventario',
            'categories' => 'categorías', 'category' => 'categoría', 'subcategories' => 'subcategorías', 'subcategory' => 'subcategoría', 'brands' => 'marcas', 'brand' => 'marca',
            'units' => 'unidades', 'unit' => 'unidad', 'employee' => 'empleados', 'employees' => 'empleados', 'attendance' => 'asistencia', 'leave' => 'permisos y vacaciones',
            'holiday' => 'feriados', 'payroll' => 'planilla', 'expense' => 'gastos', 'deposit' => 'depósitos', 'accounts' => 'cuentas', 'account' => 'cuentas',
            'settings' => 'configuración', 'setting' => 'configuración', 'backup' => 'copias de seguridad', 'currency' => 'monedas', 'shipment' => 'envíos',
            'projects' => 'proyectos', 'project' => 'proyecto', 'tasks' => 'tareas', 'task' => 'tarea', 'warranty' => 'garantías', 'barcode' => 'códigos de barras',
        ];
        $tokens = preg_split('/\s+/', $lower);
        $tokens = array_map(fn ($token) => $words[$token] ?? $token, $tokens);
        return trim(implode(' ', $tokens));
    }

    private function descriptionFor(string $name, string $action, string $label): string
    {
        $explicit = [
            'Pos_view' => 'Permite abrir y utilizar el punto de venta para registrar operaciones autorizadas.',
            'record_view' => 'Permite consultar registros creados por otros usuarios, siempre dentro del alcance asignado.',
            'payment_sales_add' => 'Permite registrar pagos recibidos de clientes sobre ventas existentes.',
            'payment_sales_edit' => 'Permite corregir información de pagos de ventas ya registrados.',
            'transfer_receive' => 'Permite confirmar la recepción física de mercancía enviada a una ubicación autorizada.',
            'transfer_issue_manage' => 'Permite resolver faltantes o productos defectuosos detectados durante una recepción.',
            'cash_register_override_assignment' => 'Permite operar una caja distinta a la asignada. Debe concederse únicamente cuando sea necesario.',
            'batch_force_override' => 'Permite ignorar bloqueos de seguridad relacionados con lotes o vencimientos.',
            'batch_writeoff' => 'Permite retirar existencias de un lote mediante una baja controlada.',
            'permissions_edit' => 'Permite cambiar lo que otros roles pueden hacer dentro de PRODEX.',
            'users_edit' => 'Permite modificar cuentas de usuario dentro del alcance permitido.',
            'users_delete' => 'Permite desactivar o eliminar cuentas de usuario autorizadas.',
        ];
        if (isset($explicit[$name])) return $explicit[$name];

        return match ($action) {
            'view' => 'Permite consultar esta información dentro del alcance asignado al usuario.',
            'create' => 'Permite registrar nueva información u operaciones de este tipo.',
            'update' => 'Permite modificar información u operaciones existentes de este tipo.',
            'delete' => 'Permite eliminar o anular información de este tipo. Conceder solo cuando sea necesario.',
            default => 'Habilita una función especial relacionada con '.lcfirst($label).'.',
        };
    }

    private function moduleLabel(string $module): string
    {
        return ['access' => 'Usuarios y accesos', 'sales' => 'Ventas y POS', 'inventory' => 'Inventario', 'purchases' => 'Compras y proveedores', 'customers' => 'Clientes', 'hr' => 'Recursos Humanos', 'accounting' => 'Contabilidad', 'reports' => 'Reportes', 'settings' => 'Configuración', 'projects' => 'Proyectos y tareas', 'other' => 'Otros'][$module] ?? ucfirst($module);
    }

    private function moduleDescription(string $module): string
    {
        return [
            'access' => 'Controla usuarios, roles y el alcance administrativo.',
            'sales' => 'Ventas, cobros, devoluciones, cotizaciones y punto de venta.',
            'inventory' => 'Productos, existencias, transferencias, ajustes, daños y lotes.',
            'purchases' => 'Compras, proveedores, pagos y devoluciones de compra.',
            'customers' => 'Información y gestión de clientes.',
            'hr' => 'Empleados, asistencia, turnos, vacaciones y planilla.',
            'accounting' => 'Cuentas, gastos, depósitos y operaciones financieras.',
            'reports' => 'Consulta de reportes e indicadores del negocio.',
            'settings' => 'Configuraciones que afectan el funcionamiento general de PRODEX.',
            'projects' => 'Proyectos, tareas y seguimiento del trabajo.',
            'other' => 'Funciones adicionales que no pertenecen a un módulo principal.',
        ][$module] ?? '';
    }

    private function moduleOrder(): array
    {
        return ['access', 'sales', 'inventory', 'purchases', 'customers', 'hr', 'accounting', 'reports', 'projects', 'settings', 'other'];
    }
}
