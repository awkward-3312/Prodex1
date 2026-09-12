<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobilePosClientsRequest;
use App\Models\Client;
use App\Services\Mobile\MobilePosClientSearchService;

/**
 * General-purpose client directory for Mobile (Más > Clientes). Gated by the real
 * Customers_view permission (ClientPolicy), unlike mobile/pos/clients which is
 * scoped to Sales_pos for the checkout picker. Reuses MobilePosClientSearchService
 * so there is one client search query, not two.
 */
class MobileClientsController extends Controller
{
    public function __invoke(MobilePosClientsRequest $request, MobilePosClientSearchService $clients)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'view', Client::class);

        return response()->json([
            'data' => $clients->search(
                $request->validated('search'),
                $request->validated('page'),
                $request->validated('per_page')
            ),
        ]);
    }
}
