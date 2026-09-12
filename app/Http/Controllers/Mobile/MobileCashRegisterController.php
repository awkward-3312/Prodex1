<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\PosCashRegisterController;
use App\utils\helpers;
use Illuminate\Http\Request;

/**
 * Read-only mobile view of the authenticated user's own cash register session.
 * Reuses PosCashRegisterController::getCurrentRegister()/buildClosingSummary()
 * verbatim (native branch/location-aware register + authoritative live summary) -
 * no financial formula is duplicated here, only reshaped into a small stable
 * envelope. Never accepts a user id from Mobile: always the authenticated user.
 */
class MobileCashRegisterController extends PosCashRegisterController
{
    public function current(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $response = $this->getCurrentRegister($request, $user->id);
        $payload = $response->getData(true);
        $register = $payload['register'] ?? null;
        $summary = $payload['closing_summary'] ?? null;

        if (! $register || ! $summary) {
            return response()->json(['data' => ['status' => 'closed', 'register' => null, 'summary' => null]]);
        }

        return response()->json(['data' => [
            'status' => 'open',
            'register' => [
                'id' => $register['id'],
                'opened_at' => $register['opened_at'],
                'opening_balance' => $this->money($register['opening_balance']),
                'branch' => $this->entity($register['branch'] ?? null),
                'inventory_location' => $this->entity($register['inventory_location'] ?? null),
                'warehouse' => $this->entity($register['warehouse'] ?? null),
                'cash_drawer' => $this->entity($register['cash_drawer'] ?? null),
            ],
            'summary' => [
                'transaction_count' => (int) $summary['transaction_count'],
                'total_sales' => $this->money($summary['total_sales']),
                'cash_sales' => $this->money($summary['cash_sales']),
                'cash_in' => $this->money($summary['cash_additions']),
                'cash_out' => $this->money($summary['cash_withdrawals']),
                'cash_refunds' => $this->money($summary['cash_refunds']),
                'expected_cash' => $this->money($summary['expected_cash']),
                'card_system_total' => $this->money($summary['card_system_total']),
                'transfer_total' => $this->money($summary['transfer_total']),
                'store_credit_applied' => $this->money($summary['store_credit_applied'] ?? 0),
                'sales_by_payment_method' => collect($summary['sales_by_payment_method'] ?? [])->map(fn ($row) => [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'total' => $this->money($row['total']),
                ])->values()->all(),
            ],
        ]]);
    }

    private function money($value): string
    {
        return number_format((float) ($value ?? 0), helpers::price_decimals(), '.', '');
    }

    private function entity(?array $model): ?array
    {
        if (! $model) return null;

        return ['id' => $model['id'] ?? null, 'name' => $model['name'] ?? null];
    }
}
