<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransferDamageLocationController extends BaseController
{
    public function show(Request $request, int $id)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);

        if (! Schema::hasTable('inventory_locations') || ! $transfer->to_inventory_location_id) {
            $warehouse = DB::table('warehouses')
                ->where('id', $transfer->to_warehouse_id)
                ->whereNull('deleted_at')
                ->value('name');

            return response()->json([
                'transfer_id' => (int) $transfer->id,
                'inventory_location_id' => null,
                'label' => $warehouse ?: 'Destino de transferencia',
            ]);
        }

        $location = DB::table('inventory_locations')
            ->where('id', $transfer->to_inventory_location_id)
            ->whereNull('deleted_at')
            ->first(['id', 'name', 'branch_id', 'warehouse_id']);

        if (! $location) {
            return response()->json([
                'transfer_id' => (int) $transfer->id,
                'inventory_location_id' => (int) $transfer->to_inventory_location_id,
                'label' => 'Ubicación destino',
            ]);
        }

        $ownerName = null;
        if ($location->branch_id && Schema::hasTable('branches')) {
            $ownerName = DB::table('branches')
                ->where('id', $location->branch_id)
                ->whereNull('deleted_at')
                ->value('name');
        } elseif ($location->warehouse_id) {
            $ownerName = DB::table('warehouses')
                ->where('id', $location->warehouse_id)
                ->whereNull('deleted_at')
                ->value('name');
        }

        $label = trim(($ownerName ? $ownerName.' · ' : '').($location->name ?: 'Ubicación de inventario'));

        return response()->json([
            'transfer_id' => (int) $transfer->id,
            'inventory_location_id' => (int) $location->id,
            'branch_id' => $location->branch_id ? (int) $location->branch_id : null,
            'warehouse_id' => $location->warehouse_id ? (int) $location->warehouse_id : null,
            'label' => $label,
        ]);
    }
}
