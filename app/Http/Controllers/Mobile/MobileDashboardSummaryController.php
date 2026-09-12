<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\Mobile\MobileDashboardSummaryService;
use Illuminate\Http\Request;

/**
 * Read-only Home dashboard summary. Gated by the same Sales_view permission as
 * mobile/sales (every user who can see their own sales can see today's summary);
 * top_products is only included when the user also has Reports_sales, matching
 * the stricter gate that already protects that data on the Reports screen.
 */
class MobileDashboardSummaryController extends Controller
{
    public function __invoke(Request $request, MobileDashboardSummaryService $dashboard)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'view', Sale::class);

        return response()->json([
            'data' => $dashboard->forUser($user, $user->hasPermissionName('Reports_sales')),
        ]);
    }
}
