<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileCashRegisterOpenRequest;
use App\Models\Sale;
use App\Services\Mobile\MobileCashRegisterOperationService;

/**
 * Opens the authenticated user's own cash register from Mobile. Never delegates
 * to PosCashRegisterController::openRegister() directly (see
 * MobileCashRegisterOperationService docblock) - register/summary in the
 * response are still sourced from MobileCashRegisterController::current()
 * (Phase 11) so no financial summary formula is duplicated here.
 */
class MobileCashRegisterOpenController extends Controller
{
    public function __invoke(
        MobileCashRegisterOpenRequest $request,
        MobileCashRegisterOperationService $operations,
        MobileCashRegisterController $currentRegister
    ) {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        try {
            $result = $operations->open($user, $request->validated());
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
