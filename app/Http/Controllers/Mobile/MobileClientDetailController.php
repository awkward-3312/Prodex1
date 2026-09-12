<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Mobile\MobileClientDetailService;
use Illuminate\Http\Request;

/** Read-only: client fields plus its last 5 sales, reusing the same scoped history query as mobile/sales. */
class MobileClientDetailController extends Controller
{
    public function __invoke(Request $request, MobileClientDetailService $detail, $id)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'view', Client::class);

        $client = $detail->find((int) $id);
        abort_unless($client, 404);

        return response()->json([
            'data' => [
                ...$client,
                'recent_sales' => $detail->recentSales($user, (int) $id),
            ],
        ]);
    }
}
