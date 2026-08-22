<?php

namespace App\Http\Middleware;

use App\Models\Transfer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API boundary guard for immutable transfer lifecycle states.
 *
 * Eloquent model events protect normal saves, but legacy update/delete routines
 * may alter stock before the transfer header itself is saved. This middleware
 * therefore rejects the request at the API boundary, before controller code can
 * restore or move any inventory.
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
            ->where(function ($query) {
                $query->whereIn('logistics_status', self::LOCKED)
                    ->orWhere(function ($pendingDispatch) {
                        $pendingDispatch->where('approval_status', 'approved')
                            ->whereNull('dispatched_at')
                            ->where(function ($status) {
                                $status->whereNull('logistics_status')
                                    ->orWhere('logistics_status', '')
                                    ->orWhere('logistics_status', 'pending');
                            });
                    });
            })
            ->pluck('Ref')
            ->filter()
            ->values();

        if ($locked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede editar ni eliminar una transferencia aprobada o despachada. '.
                    'Si está aprobada, debe despacharse; si ya salió del origen, debe resolverse mediante recepción/incidencias. '.
                    'Transferencia(s): '.$locked->implode(', '),
            ]);
        }
    }
}
