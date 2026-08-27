<?php

namespace App\Http\Controllers;

use App\Models\DraftSale;
use App\Models\Sale;
use App\Models\UserWarehouse;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosDraftRecentController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $user = Auth::user();
        $viewRecords = $user->hasRecordView();
        $isAllWarehouses = (bool) $user->is_all_warehouses;
        $warehouseIds = [];

        if (! $isAllWarehouses) {
            $warehouseIds = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $perPage = (int) $request->input('limit', 10);
        $page = max(1, (int) $request->input('page', 1));

        $draftSales = DraftSale::with(['client', 'warehouse', 'user'])
            ->whereNull('deleted_at');

        // Location-only cashiers legitimately have no UserWarehouse rows. Drafts
        // are still warehouse-shaped for legacy persistence, so warehouse filtering
        // would hide every held sale. Until draft_sales becomes location-native,
        // the safe fallback is to show only drafts created by that cashier.
        if (! $isAllWarehouses && empty($warehouseIds)) {
            $draftSales->where('user_id', $user->id);
        } else {
            if (! $viewRecords) {
                $draftSales->where('user_id', $user->id);
            }

            if (! $isAllWarehouses) {
                $draftSales->whereIn('warehouse_id', $warehouseIds);
            }
        }

        $totalRows = $draftSales->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        } elseif ($perPage <= 0) {
            $perPage = 10;
        }

        $drafts = $draftSales
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $data = $drafts->map(function (DraftSale $draft) {
            return [
                'id' => $draft->id,
                'date' => $draft->date,
                'Ref' => $draft->Ref,
                'warehouse_name' => optional($draft->warehouse)->name ?: 'Ubicación POS',
                'client_name' => optional($draft->client)->name ?: '',
                'GrandTotal' => number_format($draft->GrandTotal, helpers::price_decimals(), '.', ''),
                'actions' => '',
            ];
        })->values();

        return response()->json([
            'totalRows' => $totalRows,
            'draft_sales' => $data,
        ]);
    }
}
