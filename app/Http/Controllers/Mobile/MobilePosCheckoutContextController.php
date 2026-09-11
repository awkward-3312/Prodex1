<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\Mobile\MobilePosCheckoutContextService;
use Illuminate\Http\Request;

class MobilePosCheckoutContextController extends Controller
{
    public function __invoke(Request $request, MobilePosCheckoutContextService $context)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        return response()->json([
            'data' => $context->forUser($user),
        ]);
    }
}
