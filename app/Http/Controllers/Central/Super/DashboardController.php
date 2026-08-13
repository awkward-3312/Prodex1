<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $tenantsCount = Tenant::count();
        $activeSubscriptions = TenantSubscription::whereIn('status', ['active', 'trial'])->count();
        $plansCount = Plan::where('is_active', true)->count();

        // Inactive tenants: no activity in 30+ days or never active
        $inactiveTenantsCount = Tenant::where('status', '!=', Tenant::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('last_activity_at')
                  ->orWhere('last_activity_at', '<', now()->subDays(30));
            })
            ->count();

        // Expired subscriptions count
        $expiredSubscriptions = TenantSubscription::where('status', TenantSubscription::STATUS_EXPIRED)->count();

        // Suspended tenants count
        $suspendedTenantsCount = Tenant::where('status', Tenant::STATUS_SUSPENDED)->count();

        // Subscription status breakdown for donut chart
        $subscriptionStatusCounts = TenantSubscription::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        // Tenants created per month (last 6 months) for area chart
        $driver = Tenant::query()->getConnection()->getDriverName();
        $dateFormat = match ($driver) {
            'mysql' => "DATE_FORMAT(created_at, '%Y-%m')",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            default => "strftime('%Y-%m', created_at)",
        };
        $tenantsByMonth = Tenant::query()
            ->select(DB::raw("{$dateFormat} as month"), DB::raw('count(*) as count'))
            ->where('created_at', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->all();

        // Fill in missing months with 0
        $last6Months = collect();
        for ($i = 5; $i >= 0; $i--) {
            $last6Months->put(Carbon::now()->subMonths($i)->format('Y-m'), 0);
        }
        $tenantsByMonth = $last6Months->merge($tenantsByMonth)->all();

        // Subscriptions by plan (top 5) for bar chart
        $subscriptionsByPlan = TenantSubscription::query()
            ->join('plans', 'tenant_subscriptions.plan_id', '=', 'plans.id')
            ->select('plans.name as plan_name', DB::raw('count(tenant_subscriptions.id) as count'))
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'plan_name')
            ->all();

        // Pending tenants (for shared hosting mode)
        $pendingTenants = Tenant::with('domains')
            ->where('status', Tenant::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();

        return view('central.super.dashboard', [
            'tenantsCount' => $tenantsCount,
            'activeSubscriptions' => $activeSubscriptions,
            'plansCount' => $plansCount,
            'inactiveTenantsCount' => $inactiveTenantsCount,
            'expiredSubscriptions' => $expiredSubscriptions,
            'suspendedTenantsCount' => $suspendedTenantsCount,
            'subscriptionStatusCounts' => $subscriptionStatusCounts,
            'tenantsByMonth' => $tenantsByMonth,
            'subscriptionsByPlan' => $subscriptionsByPlan,
            'pendingTenants' => $pendingTenants,
        ]);
    }
}
