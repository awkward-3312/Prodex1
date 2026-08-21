<?php

namespace App\Http\Middleware;

use App\Models\Transfer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API boundary guard for immutable dispatched transfers.
 *
 * Eloquent model events already protect normal saves, but bulk-delete/update code
 * can bypass model events. This middleware closes that path before any legacy
 * controller code is allowed to delete details or mutate a shipment that has left
 * the source warehouse.
 */
class ProtectDispatchedTransferMutation
{
    private const LOCKED = ['in_transit', 'partially_received', 'received', 'received_with_issues'];

    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();
        $action = $route && method_exists($route, 'getActionName') ? (string) $route->getActionName() : '';

        if (! str_contains($action, 'TransferController@')) {
            return $next($request);
        }

        if (str_ends_with($action, 'TransferController@update') || str_ends_with($action, 'TransferController@destroy')) {
            $id = (int) ($request->route('transfer') ?: $request->route('id'));
            if ($id > 0) {
                $this->assertMutable([$id]);
            }
        }

        if (str_ends_with($action, 'TransferController@delete_by_selection')) {
            $ids = array_values(array_filter(array_map('intval', (array) $request->input('selectedIds', []))));
            if ($ids) {
                $this->assertMutable($ids);
            }
        }

        return $next($request);
    }

    private function assertMutable(array $ids): void
    {
        $locked = Transfer::whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->whereIn('logistics_status', self::LOCKED)
            ->pluck('Ref')
            ->filter()
            ->values();

        if ($locked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede editar ni eliminar una transferencia despachada. Debe resolverse mediante recepción/incidencias. '.
                    'Transferencia(s): '.$locked->implode(', '),
            ]);
        }
    }
}
