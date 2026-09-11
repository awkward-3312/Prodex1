<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileSalesHistoryRequest;
use App\Models\Sale;
use App\Services\Mobile\MobileSalesHistoryService;

class MobileSalesHistoryController extends Controller
{
    public function __invoke(MobileSalesHistoryRequest $request, MobileSalesHistoryService $history)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'view', Sale::class);

        return response()->json([
            'data' => $history->history(
                $user,
                $request->validated('search'),
                $request->validated('payment_status'),
                $request->validated('date_from'),
                $request->validated('date_to'),
                $request->validated('page'),
                $request->validated('per_page')
            ),
        ]);
    }
}
