<?php

namespace App\Services\Mobile;

use App\Models\User;
use Carbon\Carbon;

/**
 * Orchestrates the Mobile home-screen summary purely from MobileReportsSummaryService
 * (today, yesterday, current-week daily totals) - no separate formula. Deltas are
 * computed only when a real, non-zero baseline exists; otherwise null (never a
 * fabricated percentage).
 */
class MobileDashboardSummaryService
{
    public function __construct(private MobileReportsSummaryService $reports) {}

    public function forUser(User $user, bool $includeTopProducts): array
    {
        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        $todaySummary = $this->reports->summary($user, $today->toDateString(), $today->toDateString());
        $yesterdaySummary = $this->reports->summary($user, $yesterday->toDateString(), $yesterday->toDateString());
        $week = $this->reports->dailyTotals($user, $weekStart->toDateString(), $today->toDateString());

        return [
            'today' => [
                'sales_total' => $todaySummary['sales_total'],
                'sales_count' => $todaySummary['sales_count'],
                'average_sale' => $todaySummary['average_sale'],
                'sales_total_delta_pct' => $this->deltaPct($todaySummary['sales_total'], $yesterdaySummary['sales_total']),
                'average_sale_delta_pct' => $this->deltaPct($todaySummary['average_sale'], $yesterdaySummary['average_sale']),
                'top_products' => $includeTopProducts ? $todaySummary['top_products'] : null,
            ],
            'week' => [
                'from' => $weekStart->toDateString(),
                'to' => $today->toDateString(),
                'days' => $week,
                'total' => number_format(array_sum(array_map(fn ($day) => (float) $day['total'], $week)), 2, '.', ''),
            ],
        ];
    }

    private function deltaPct(string $current, string $baseline): ?float
    {
        $baselineValue = (float) $baseline;
        if ($baselineValue <= 0.0) return null;

        return round(((float) $current - $baselineValue) / $baselineValue * 100, 1);
    }
}
