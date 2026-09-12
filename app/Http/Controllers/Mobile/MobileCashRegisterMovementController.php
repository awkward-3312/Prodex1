<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileCashRegisterMovementRequest;
use App\Models\Sale;
use App\Services\Mobile\MobileCashRegisterOperationService;

/**
 * Cash-in / cash-out movement on the authenticated user's own open register.
 * Summary/register in the response come from MobileCashRegisterController::
 * current() (Phase 11) - same authoritative calculation, never recomputed here.
 */
class MobileCashRegisterMovementController extends Controller
{
    public function __invoke(
        MobileCashRegisterMovementRequest $request,
        MobileCashRegisterOperationService $operations,
        MobileCashRegisterController $currentRegister
    ) {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        try {
            $result = $operations->movement($user, $request->validated());
        } catch (MobilePosPreflightException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'details' => $e->details(),
                ],
            ], $e->statusCode());
        }

        $current = $currentRegister->current($request)->getData(true);

        return response()->json([
            'success' => true,
            'idempotent' => $result['idempotent'],
            'operation' => [
                'operation_uuid' => $result['operation']->operation_uuid,
                'operation_type' => $result['operation']->operation_type,
            ],
            'register' => $current['data']['register'] ?? null,
            'summary' => $current['data']['summary'] ?? null,
        ]);
    }
}
