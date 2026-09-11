<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobilePosSalePreflightRequest;
use App\Models\Sale;
use App\Services\Mobile\MobilePosSalePreflightService;

class MobilePosSalePreflightController extends Controller
{
    public function __invoke(MobilePosSalePreflightRequest $request, MobilePosSalePreflightService $preflight)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        try {
            $data = $preflight->preflight($user, $request->validated());
        } catch (MobilePosPreflightException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'details' => $e->details(),
                ],
            ], $e->statusCode());
        }

        return response()->json(['data' => $data]);
    }
}
