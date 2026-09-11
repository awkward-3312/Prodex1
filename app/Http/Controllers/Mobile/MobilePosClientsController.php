<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobilePosClientsRequest;
use App\Models\Sale;
use App\Services\Mobile\MobilePosClientSearchService;

class MobilePosClientsController extends Controller
{
    public function __invoke(MobilePosClientsRequest $request, MobilePosClientSearchService $clients)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        return response()->json([
            'data' => $clients->search(
                $request->validated('search'),
                $request->validated('page'),
                $request->validated('per_page')
            ),
        ]);
    }
}
