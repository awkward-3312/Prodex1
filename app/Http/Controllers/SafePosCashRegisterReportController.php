<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the native POS cash-register report available even when a tenant still
 * has an older cash-register schema. Native reporting remains the preferred
 * path; the legacy report is only a read-only compatibility fallback.
 */
class SafePosCashRegisterReportController extends PosCashRegisterReportController
{
    public function report(
        Request $request,
        \App\Services\BranchScopeService $branchScope,
        \App\Services\InventoryLocationScopeService $locationScope
    ) {
        try {
            return parent::report($request, $branchScope, $locationScope);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Native cash register report failed; attempting read-only legacy compatibility fallback.', [
                'tenant_id' => function_exists('tenant') && tenant() ? (string) tenant()->id : null,
                'user_id' => optional($request->user('api'))->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            try {
                $legacy = app(CashRegisterController::class)->report($request);
                $payload = method_exists($legacy, 'getData') ? $legacy->getData(true) : [];

                return response()->json([
                    'totalRows' => (int) ($payload['totalRows'] ?? 0),
                    'registers' => $payload['registers'] ?? [],
                    'users' => $payload['users'] ?? [],
                    'branches' => [],
                    'inventory_locations' => [],
                    'cash_drawers' => [],
                    'legacy_warehouses' => $payload['warehouses'] ?? [],
                    'compatibility_mode' => true,
                    'compatibility_message' => 'El tenant usa temporalmente el modo histórico del reporte de caja. Ejecuta la actualización de tenants para habilitar el contexto nativo completo.',
                ], Response::HTTP_OK);
            } catch (\Throwable $fallbackError) {
                Log::error('Cash register report compatibility fallback also failed.', [
                    'tenant_id' => function_exists('tenant') && tenant() ? (string) tenant()->id : null,
                    'user_id' => optional($request->user('api'))->id,
                    'native_error' => $e->getMessage(),
                    'fallback_error' => $fallbackError->getMessage(),
                ]);

                return response()->json([
                    'message' => 'No se pudo cargar el informe de caja.',
                    'error_code' => 'cash_register_report_unavailable',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }
    }
}
