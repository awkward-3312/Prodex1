<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\SafePosCashRegisterReportController;
use App\utils\helpers;
use Illuminate\Http\Request;

/**
 * Read-only mobile cash-register session history. Reuses
 * SafePosCashRegisterReportController::report() verbatim - same cash_register_report
 * permission, same tenant/branch/record visibility, same frozen closing_snapshot
 * data for closed sessions - reshaped into a small stable, paginated envelope.
 */
class MobileCashRegisterHistoryController extends SafePosCashRegisterReportController
{
    public function history(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $page = max(1, (int) ($request->get('page') ?: 1));
        $perPage = min(50, max(1, (int) ($request->get('per_page') ?: 20)));

        // Only forward the parameters this Mobile contract exposes - never let
        // Mobile widen the query beyond what the native report already scopes.
        $native = Request::create('', 'GET', array_filter([
            'from' => $request->get('from'),
            'to' => $request->get('to'),
            'limit' => $perPage,
            'page' => $page,
        ], fn ($value) => $value !== null));
        $native->setUserResolver($request->getUserResolver());

        $response = $this->report($native);
        $payload = $response->getData(true);
        $totalRows = (int) ($payload['totalRows'] ?? 0);
        $rows = $payload['registers'] ?? [];

        return response()->json(['data' => [
            'items' => array_map([$this, 'shapeRegister'], $rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalRows,
                'last_page' => max(1, (int) ceil($totalRows / $perPage)),
                'has_more' => ($page * $perPage) < $totalRows,
            ],
        ]]);
    }

    private function shapeRegister(array $row): array
    {
        $decimals = helpers::price_decimals();
        $money = fn ($value) => $value === null ? null : number_format((float) $value, $decimals, '.', '');

        return [
            'id' => $row['id'] ?? null,
            'status' => $row['status'] ?? 'closed',
            'closing_status' => $row['closing_status'] ?? null,
            'closing_status_label' => $row['closing_status_label'] ?? null,
            'user' => [
                'id' => $row['user_id'] ?? null,
                'name' => $row['cashier_name'] ?? $row['opened_by_user_name'] ?? null,
            ],
            'branch' => $row['branch_name'] ?? null,
            'inventory_location' => $row['inventory_location_name'] ?? null,
            'warehouse' => $row['warehouse_name'] ?? null,
            'cash_drawer' => $row['cash_drawer_name'] ?? null,
            'opened_at' => $row['opened_at'] ?? null,
            'closed_at' => $row['closed_at'] ?? null,
            'opening_balance' => $money($row['opening_balance'] ?? null),
            'total_sales' => $money($row['total_sales'] ?? null),
            'expected_cash' => $money($row['expected_cash'] ?? null),
            'counted_cash' => $money($row['counted_cash'] ?? null),
            'difference' => $money($row['difference'] ?? null),
        ];
    }
}
