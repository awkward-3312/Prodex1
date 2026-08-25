<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NotificationCenterController extends Controller
{
    private const CATEGORIES = [
        'inventory' => 'Inventario',
        'transfers' => 'Transferencias',
        'meetings' => 'Reuniones',
        'assets' => 'Activos',
        'purchases' => 'Compras',
        'pos' => 'POS',
        'hr' => 'RRHH',
        'accounting' => 'Contabilidad',
        'system' => 'Sistema',
    ];

    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user('api');
        abort_unless($user, 401);

        $items = collect();
        $this->appendTransferNotifications($items, $user);
        $this->appendLaravelNotifications($items, $user);
        $this->appendTransferDiscrepancies($items, $user);
        $this->appendInventoryAlerts($items, $user);

        $notifications = $items
            ->sortByDesc(fn (array $item) => (string) ($item['created_at'] ?? ''))
            ->take(40)
            ->values();

        return response()->json([
            'notifications' => $notifications,
            'unread' => $notifications->where('unread', true)->count(),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function markLaravelNotificationRead(Request $request, string $notificationId)
    {
        /** @var User|null $user */
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless(Schema::hasTable('notifications'), 404);

        $updated = DB::table('notifications')
            ->where('id', $notificationId)
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        if (! $updated) {
            $exists = DB::table('notifications')
                ->where('id', $notificationId)
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id', $user->id)
                ->exists();
            abort_unless($exists, 404);
        }

        return response()->json(['success' => true]);
    }

    private function appendTransferNotifications($items, User $user): void
    {
        if (! Schema::hasTable('transfer_notifications') || ! Schema::hasTable('transfers')) {
            return;
        }

        $rows = DB::table('transfer_notifications as n')
            ->join('transfers as t', 't.id', '=', 'n.transfer_id')
            ->where('n.user_id', $user->id)
            ->whereNull('t.deleted_at')
            ->orderByDesc('n.created_at')
            ->limit(20)
            ->get(['n.id', 'n.transfer_id', 'n.title', 'n.message', 'n.read_at', 'n.created_at', 't.Ref as reference']);

        foreach ($rows as $row) {
            $items->push([
                'key' => 'transfer:'.$row->id,
                'category' => 'transfers',
                'type' => 'transfer_update',
                'title' => trim(($row->title ?: 'Transferencia').($row->reference ? ' · '.$row->reference : '')),
                'message' => $row->message ?: 'Hay una actualización de transferencia.',
                'unread' => ! $row->read_at,
                'persistent' => true,
                'created_at' => $row->created_at,
                'action' => '/app/transfers/detail/'.$row->transfer_id,
                'read_endpoint' => '/api/transfer-logistics/notifications/'.$row->id.'/read',
            ]);
        }
    }

    private function appendLaravelNotifications($items, User $user): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $rows = DB::table('notifications')
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'type', 'data', 'read_at', 'created_at']);

        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true);
            if (! is_array($data)) {
                $data = [];
            }

            $type = (string) ($data['type'] ?? class_basename((string) $row->type));
            $category = $this->categoryFor($type, $data);

            $items->push([
                'key' => 'laravel:'.$row->id,
                'category' => $category,
                'type' => $type,
                'title' => $this->titleFor($type, $data),
                'message' => (string) ($data['message'] ?? $this->titleFor($type, $data)),
                'unread' => ! $row->read_at,
                'persistent' => true,
                'created_at' => $row->created_at,
                'action' => $this->actionFor($type, $data),
                'read_endpoint' => '/api/notification-center/'.$row->id.'/read',
            ]);
        }
    }

    private function appendTransferDiscrepancies($items, User $user): void
    {
        if (! Schema::hasTable('transfer_discrepancies') || ! Schema::hasTable('transfers')) {
            return;
        }

        $canSeeIssues = (int) $user->role_id === 1
            || $user->hasPermissionName('transfer_issue_manage')
            || $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION)
            || $user->hasPermissionName('transfer_view')
            || $user->hasPermissionName('damage_view')
            || $user->hasPermissionName('product_view');

        if (! $canSeeIssues) {
            return;
        }

        $warehouseIds = app(TransferLogisticsService::class)->warehouseIdsForUser($user);
        $locationIds = Schema::hasTable('inventory_locations')
            ? app(InventoryLocationScopeService::class)->allowedLocationIds($user)
            : [];
        $hasLocations = Schema::hasColumn('transfers', 'from_inventory_location_id')
            && Schema::hasColumn('transfers', 'to_inventory_location_id');

        $query = DB::table('transfer_discrepancies as d')
            ->join('transfers as t', 't.id', '=', 'd.transfer_id')
            ->whereNull('t.deleted_at')
            ->where('d.resolution_status', 'open');

        if ((int) $user->role_id !== 1) {
            $query->where(function ($scope) use ($warehouseIds, $locationIds, $hasLocations) {
                if ($hasLocations && $locationIds) {
                    $scope->whereIn('t.to_inventory_location_id', $locationIds)
                        ->orWhereIn('t.from_inventory_location_id', $locationIds);
                }
                if ($warehouseIds) {
                    $scope->orWhere(function ($legacy) use ($warehouseIds, $hasLocations) {
                        if ($hasLocations) {
                            $legacy->whereNull('t.from_inventory_location_id')
                                ->whereNull('t.to_inventory_location_id');
                        }
                        $legacy->where(function ($w) use ($warehouseIds) {
                            $w->whereIn('t.to_warehouse_id', $warehouseIds)
                                ->orWhereIn('t.from_warehouse_id', $warehouseIds);
                        });
                    });
                }
            });
        }

        $open = $query->orderByDesc('d.reported_at')
            ->limit(20)
            ->get(['d.id', 'd.type', 'd.quantity', 'd.reported_at', 't.Ref as reference']);

        foreach ($open as $row) {
            $items->push([
                'key' => 'issue:'.$row->id,
                'category' => 'inventory',
                'type' => 'transfer_discrepancy',
                'title' => ($row->type === 'missing' ? 'Faltante' : 'Producto defectuoso').' · '.$row->reference,
                'message' => 'Incidencia abierta por '.number_format((float) $row->quantity, 3, '.', ',').' unidad(es).',
                'unread' => true,
                'persistent' => false,
                'created_at' => $row->reported_at,
                'action' => '/app/inventory/missing',
                'read_endpoint' => null,
            ]);
        }
    }

    private function appendInventoryAlerts($items, User $user): void
    {
        if (! Schema::hasTable('product_warehouse') || ! Schema::hasTable('products')) {
            return;
        }

        $canSee = (int) $user->role_id === 1
            || $user->hasPermissionName('Reports_quantity_alerts')
            || $user->hasPermissionName('product_view');

        if (! $canSee) {
            return;
        }

        $query = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->whereNull('pw.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereRaw('pw.qte <= p.stock_alert');

        if ((int) $user->role_id !== 1) {
            $warehouseIds = app(TransferLogisticsService::class)->warehouseIdsForUser($user);
            if (! $warehouseIds) {
                return;
            }
            $query->whereIn('pw.warehouse_id', $warehouseIds);
        }

        $lowStock = (clone $query)->distinct()->count('pw.product_id');
        if ($lowStock <= 0) {
            return;
        }

        $outOfStock = (clone $query)->where('pw.qte', '<=', 0)->distinct()->count('pw.product_id');
        $message = $lowStock.' producto(s) requieren atención por nivel de existencias.';
        if ($outOfStock > 0) {
            $message .= ' '.$outOfStock.' agotado(s).';
        }

        $items->push([
            'key' => 'inventory:stock-alerts',
            'category' => 'inventory',
            'type' => 'stock_alert',
            'title' => 'Stock bajo · '.$lowStock.' producto(s)',
            'message' => $message,
            'unread' => true,
            'persistent' => false,
            'created_at' => now()->toDateTimeString(),
            'action' => '/app/reports/quantity_alerts',
            'read_endpoint' => null,
        ]);
    }

    private function categoryFor(string $type, array $data): string
    {
        if (! empty($data['category']) && isset(self::CATEGORIES[$data['category']])) {
            return $data['category'];
        }

        $normalized = Str::lower($type);
        $map = [
            'meeting' => 'meetings',
            'asset' => 'assets',
            'transfer' => 'transfers',
            'inventory' => 'inventory',
            'product' => 'inventory',
            'stock' => 'inventory',
            'purchase' => 'purchases',
            'supplier' => 'purchases',
            'sale' => 'pos',
            'pos' => 'pos',
            'cash_drawer' => 'pos',
            'attendance' => 'hr',
            'leave' => 'hr',
            'holiday' => 'hr',
            'employee' => 'hr',
            'payroll' => 'hr',
            'account' => 'accounting',
            'expense' => 'accounting',
            'invoice' => 'accounting',
            'payment' => 'accounting',
        ];

        foreach ($map as $needle => $category) {
            if (Str::contains($normalized, $needle)) {
                return $category;
            }
        }

        return 'system';
    }

    private function titleFor(string $type, array $data): string
    {
        if (! empty($data['notification_title'])) return (string) $data['notification_title'];
        if (! empty($data['title'])) {
            if (Str::contains(Str::lower($type), 'meeting')) {
                return (Str::contains(Str::lower($type), 'invitation') ? 'Invitación a reunión' : 'Recordatorio de reunión').' · '.$data['title'];
            }
            return (string) $data['title'];
        }
        if ($type === 'asset_validation_due' && ! empty($data['asset_name'])) return 'Validación de activo · '.$data['asset_name'];

        return Str::headline($type ?: 'Notificación');
    }

    private function actionFor(string $type, array $data): ?string
    {
        foreach (['action', 'url', 'route'] as $field) {
            if (! empty($data[$field]) && is_string($data[$field]) && Str::startsWith($data[$field], '/')) {
                return $data[$field];
            }
        }

        $normalized = Str::lower($type);
        if (Str::contains($normalized, 'meeting') && ! empty($data['meeting_id'])) {
            return '/app/meeting/details/'.(int) $data['meeting_id'];
        }
        if (Str::contains($normalized, 'asset') && ! empty($data['asset_id'])) {
            return '/app/assets/edit/'.(int) $data['asset_id'];
        }

        return null;
    }
}
