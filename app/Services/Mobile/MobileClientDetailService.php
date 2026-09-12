<?php

namespace App\Services\Mobile;

use App\Models\Client;
use App\Models\User;
use App\utils\helpers;
use Illuminate\Support\Facades\DB;

/**
 * Read-only client detail for Mobile. The balance figure follows the same
 * authoritative formula already used by ClientController::index and the
 * customer reports (opening_balance + sales due - sale-return due) - computed
 * here from real DB sums, never estimated client-side.
 */
class MobileClientDetailService
{
    public function __construct(private MobileSalesHistoryService $salesHistory) {}

    public function find(int $clientId): ?array
    {
        $client = Client::whereNull('deleted_at')->find($clientId);
        if (! $client) return null;

        $decimals = helpers::price_decimals();

        $sales = DB::table('sales')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->where('statut', 'completed')
            ->selectRaw('COALESCE(SUM(GrandTotal),0) as total_amount, COALESCE(SUM(paid_amount),0) as total_paid')
            ->first();

        $returns = DB::table('sale_returns')
            ->whereNull('deleted_at')
            ->where('client_id', $clientId)
            ->selectRaw('COALESCE(SUM(GrandTotal),0) as total_amount_return, COALESCE(SUM(paid_amount),0) as total_paid_return')
            ->first();

        $due = (float) $sales->total_amount - (float) $sales->total_paid;
        $returnDue = (float) $returns->total_amount_return - (float) $returns->total_paid_return;
        $balance = (float) $client->opening_balance + $due - $returnDue;

        return [
            'id' => (int) $client->id,
            'name' => $client->name,
            'rtn' => $client->tax_number,
            'phone' => $client->phone,
            'email' => $client->email,
            'address' => $client->adresse,
            'balance' => number_format($balance, $decimals, '.', ''),
        ];
    }

    public function recentSales(User $user, int $clientId, int $limit = 5): array
    {
        return $this->salesHistory->history(user: $user, page: 1, perPage: $limit, clientId: $clientId)['items'];
    }
}
