<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileReportsSummaryRequest;
use App\Models\Sale;
use App\Services\Mobile\MobileReportsSummaryService;

/** Read-only mobile reports dashboard. Never mutates anything; from/to are the only client-controlled input. */
class MobileReportsSummaryController extends Controller
{
    public function __invoke(MobileReportsSummaryRequest $request, MobileReportsSummaryService $reports)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Reports_sales', Sale::class);

        return response()->json([
            'data' => $reports->summary($user, $request->validated('from'), $request->validated('to')),
        ]);
    }
}
