<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobilePosSaleSubmitRequest;
use App\Models\Sale;
use App\Services\Mobile\MobilePosSaleSubmissionService;

class MobilePosSaleSubmitController extends Controller
{
    public function __invoke(MobilePosSaleSubmitRequest $request, MobilePosSaleSubmissionService $sales)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        try {
            $data = $sales->submit($user, $request->validated());
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
