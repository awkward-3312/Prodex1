<?php

namespace App\Services\Marketing;

use App\Models\MarketingSegment;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a marketing segment (or "all customers") into the matching clients,
 * enriched with per-customer purchase statistics used for personalization.
 *
 * Supported filter keys (all optional):
 *   city                  string   exact city match
 *   last_purchase_from    date     last purchase on/after this date
 *   last_purchase_to      date     last purchase on/before this date
 *   total_purchases_min   int      minimum number of completed sales
 *   total_purchases_max   int      maximum number of completed sales
 *   total_spent_min       number   minimum total amount spent
 *   total_spent_max       number   maximum total amount spent
 *
 * NOTE: "Customer Group" segmentation from the feature request is not implemented
 * because Stocky currently has no customer-group concept on the clients table.
 */
class SegmentResolver
{
    /**
     * Build the base clients query joined with aggregated sales statistics.
     */
    public function baseQuery()
    {
        $salesAgg = DB::table('sales')
            ->select(
                'client_id',
                DB::raw('COUNT(*) as purchases_count'),
                DB::raw('SUM(GrandTotal) as total_spent'),
                DB::raw('MAX(date) as last_purchase')
            )
            ->whereNull('deleted_at')
            ->where('statut', 'completed')
            ->groupBy('client_id');

        return DB::table('clients')
            ->leftJoinSub($salesAgg, 'sa', 'sa.client_id', '=', 'clients.id')
            ->whereNull('clients.deleted_at')
            ->select(
                'clients.id',
                'clients.name',
                'clients.firstname',
                'clients.lastname',
                'clients.email',
                'clients.phone',
                'clients.city',
                'clients.country',
                DB::raw('COALESCE(sa.purchases_count, 0) as purchases_count'),
                DB::raw('COALESCE(sa.total_spent, 0) as total_spent'),
                'sa.last_purchase'
            );
    }

    /**
     * Apply segment filters to the base query.
     */
    public function applyFilters($query, array $filters)
    {
        if (! empty($filters['city'])) {
            $query->where('clients.city', $filters['city']);
        }
        if (! empty($filters['last_purchase_from'])) {
            $query->havingRaw('last_purchase >= ?', [$filters['last_purchase_from']]);
        }
        if (! empty($filters['last_purchase_to'])) {
            $query->havingRaw('last_purchase <= ?', [$filters['last_purchase_to']]);
        }
        if (isset($filters['total_purchases_min']) && $filters['total_purchases_min'] !== '' && $filters['total_purchases_min'] !== null) {
            $query->havingRaw('purchases_count >= ?', [(int) $filters['total_purchases_min']]);
        }
        if (isset($filters['total_purchases_max']) && $filters['total_purchases_max'] !== '' && $filters['total_purchases_max'] !== null) {
            $query->havingRaw('purchases_count <= ?', [(int) $filters['total_purchases_max']]);
        }
        if (isset($filters['total_spent_min']) && $filters['total_spent_min'] !== '' && $filters['total_spent_min'] !== null) {
            $query->havingRaw('total_spent >= ?', [(float) $filters['total_spent_min']]);
        }
        if (isset($filters['total_spent_max']) && $filters['total_spent_max'] !== '' && $filters['total_spent_max'] !== null) {
            $query->havingRaw('total_spent <= ?', [(float) $filters['total_spent_max']]);
        }

        return $query;
    }

    /**
     * Resolve clients for an "all customers" flag + filter array.
     *
     * @return \Illuminate\Support\Collection
     */
    public function resolve(bool $allCustomers, array $filters = [])
    {
        $query = $this->baseQuery();

        if (! $allCustomers) {
            $this->applyFilters($query, $filters);
        }

        return $query->get();
    }

    /**
     * Resolve clients for a saved segment model.
     *
     * @return \Illuminate\Support\Collection
     */
    public function resolveSegment(MarketingSegment $segment)
    {
        return $this->resolve((bool) $segment->all_customers, $segment->filters ?? []);
    }

    /**
     * Count matching clients without fetching the full result set.
     */
    public function count(bool $allCustomers, array $filters = []): int
    {
        return $this->resolve($allCustomers, $filters)->count();
    }
}
