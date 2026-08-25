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

        $groups = $names
            ->map(fn (string $name) => $this->describe($name, $known->all()))
            ->groupBy('module')
            ->map(function ($items, $key) {
                return [
                    'key' => $key,
                    'label' => $this->moduleLabel($key),
                    'permissions' => $items->sortBy(fn ($item) => [$item['action_order'], $item['label']])->values()->all(),
                ];
            })
            ->sortBy(fn ($group) => array_search($group['key'], $this->moduleOrder(), true) ?: 999)
            ->values()
            ->all();

        return [
            'groups' => $groups,
            'sensitive' => collect($groups)->flatMap(fn ($group) => $group['permissions'])->where('sensitive', true)->values()->all(),
            'presets' => ['read_only' => 'Solo lectura', 'operator' => 'Operador', 'manager' => 'Administrador del módulo'],
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
        return [
            'name' => $name,
            'label' => $this->labelFor($name),
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
            'dashboard' => 'Tablero', 'Pos_view' => 'Punto de venta', 'record_view' => 'Ver registros de otros usuarios',
            'transfer_receive' => 'Recibir transferencias', 'transfer_issue_manage' => 'Resolver incidencias de transferencias',
            'cash_register_override_assignment' => 'Operar fuera de la asignación de caja',
            'batch_force_override' => 'Sobrescribir bloqueo por vencimiento', 'batch_writeoff' => 'Dar de baja lotes',
            'setting_system' => 'Configuración general del sistema', 'module_settings' => 'Administrar módulos',
        ];
        if (isset($explicit[$name])) return $explicit[$name];
        $label = str_replace(['_', '-'], ' ', $name);
        $label = preg_replace('/\bview\b/i', 'Ver', $label);
        $label = preg_replace('/\badd\b/i', 'Crear', $label);
        $label = preg_replace('/\bedit\b/i', 'Editar', $label);
        $label = preg_replace('/\bdelete\b/i', 'Eliminar', $label);
        $label = preg_replace('/\breport(s)?\b/i', 'Reporte', $label);
        return ucfirst(trim(preg_replace('/\s+/', ' ', $label)));
    }

    private function moduleLabel(string $module): string
    {
        return ['access' => 'Usuarios y accesos', 'sales' => 'Ventas y POS', 'inventory' => 'Inventario', 'purchases' => 'Compras y proveedores', 'customers' => 'Clientes', 'hr' => 'Recursos Humanos', 'accounting' => 'Contabilidad', 'reports' => 'Reportes', 'settings' => 'Configuración', 'projects' => 'Proyectos y tareas', 'other' => 'Otros'][$module] ?? ucfirst($module);
    }

    private function moduleOrder(): array
    {
        return ['access', 'sales', 'inventory', 'purchases', 'customers', 'hr', 'accounting', 'reports', 'projects', 'settings', 'other'];
    }
}
