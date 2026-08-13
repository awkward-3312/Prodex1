<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        $now = Carbon::now();

        // ── KPI stats ────────────────────────────────────────
        $totalTenants       = Tenant::count();
        $activeTenants      = Tenant::where('status', Tenant::STATUS_ACTIVE)->count();
        $activeSubscriptions = TenantSubscription::whereIn('status', ['active', 'trial'])->count();
        $totalRevenue       = TenantBillingPayment::on('central')->where('status', 'paid')->sum('amount');

        // MRR (Monthly Recurring Revenue) — active monthly subscriptions
        $mrr = TenantSubscription::where('status', TenantSubscription::STATUS_ACTIVE)
            ->where('billing_cycle', 'monthly')
            ->sum('amount');
        $mrrYearly = TenantSubscription::where('status', TenantSubscription::STATUS_ACTIVE)
            ->where('billing_cycle', 'yearly')
            ->sum(DB::raw('amount / 12'));
        $mrr += $mrrYearly;

        // Churn rate — cancelled in last 30 days / total active at start
        $cancelledLast30 = TenantSubscription::where('status', TenantSubscription::STATUS_CANCELLED)
            ->where('cancelled_at', '>=', $now->copy()->subDays(30))
            ->count();
        $churnRate = $activeSubscriptions > 0
            ? round(($cancelledLast30 / ($activeSubscriptions + $cancelledLast30)) * 100, 1)
            : 0;

        // ── Tenant status breakdown ──────────────────────────
        $tenantStatuses = Tenant::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        // ── Subscription status breakdown ────────────────────
        $subscriptionStatuses = TenantSubscription::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        // ── Tenant growth — last 12 months ───────────────────
        $driver = Tenant::query()->getConnection()->getDriverName();
        $dateFormat = match ($driver) {
            'mysql'  => "DATE_FORMAT(created_at, '%Y-%m')",
            'pgsql'  => "to_char(created_at, 'YYYY-MM')",
            default  => "strftime('%Y-%m', created_at)",
        };

        $tenantGrowthRaw = Tenant::query()
            ->select(DB::raw("{$dateFormat} as month"), DB::raw('count(*) as count'))
            ->where('created_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->all();

        $tenantGrowth = collect();
        for ($i = 11; $i >= 0; $i--) {
            $key   = $now->copy()->subMonths($i)->format('Y-m');
            $label = $now->copy()->subMonths($i)->format('M Y');
            $tenantGrowth->push([
                'month' => $label,
                'count' => $tenantGrowthRaw[$key] ?? 0,
            ]);
        }

        // ── Revenue trend — last 12 months ───────────────────
        $revenueRaw = TenantBillingPayment::on('central')
            ->where('status', 'paid')
            ->where('paid_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
            ->select(
                DB::raw("DATE_FORMAT(paid_at, '%Y-%m') as month"),
                DB::raw('SUM(amount) as revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('revenue', 'month')
            ->all();

        $revenueTrend = collect();
        for ($i = 11; $i >= 0; $i--) {
            $key   = $now->copy()->subMonths($i)->format('Y-m');
            $label = $now->copy()->subMonths($i)->format('M Y');
            $revenueTrend->push([
                'month'   => $label,
                'revenue' => (float) ($revenueRaw[$key] ?? 0),
            ]);
        }

        // ── Subscriptions by plan ────────────────────────────
        $subscriptionsByPlan = TenantSubscription::query()
            ->join('plans', 'tenant_subscriptions.plan_id', '=', 'plans.id')
            ->select('plans.name as plan_name', DB::raw('count(tenant_subscriptions.id) as count'))
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('count')
            ->get();

        // ── Top plans by revenue ─────────────────────────────
        $topPlansByRevenue = TenantBillingPayment::on('central')
            ->where('tenant_billing_payments.status', 'paid')
            ->join('plans', 'tenant_billing_payments.plan_id', '=', 'plans.id')
            ->select('plans.name as plan_name', DB::raw('SUM(tenant_billing_payments.amount) as revenue'), DB::raw('COUNT(*) as transactions'))
            ->groupBy('plans.id', 'plans.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        // ── Recent tenants ───────────────────────────────────
        $recentTenants = Tenant::with('domains')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('central.super.reports.index', compact(
            'totalTenants',
            'activeTenants',
            'activeSubscriptions',
            'totalRevenue',
            'mrr',
            'churnRate',
            'tenantStatuses',
            'subscriptionStatuses',
            'tenantGrowth',
            'revenueTrend',
            'subscriptionsByPlan',
            'topPlansByRevenue',
            'recentTenants'
        ));
    }
}
