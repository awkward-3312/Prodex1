<?php

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Http\Controllers\PosController;
use App\Models\InventoryLocationStock;
use App\Models\Sale;
use App\Models\User;
use App\Services\UserOperationalAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class MobilePosSaleSubmissionService
{
    public function __construct(private MobilePosSalePreflightService $preflight) {}

    public function submit(User $user, array $payload): array
    {
        $uuid = (string) $payload['sale_uuid'];
        $existing = Sale::with('sarFiscalDocument')->where('sale_uuid', $uuid)->first();
        if ($existing) {
            return $this->mobileResponse($existing, true, [], now()->toIso8601String());
        }

        $preflight = $this->preflight->preflight($user, [
            'client_id' => $payload['client_id'],
            'lines' => $payload['lines'],
            'payment_intent' => $payload['payments'],
        ]);

        if (! ($preflight['can_submit'] ?? false)) {
            $first = $preflight['errors'][0] ?? ['code' => 'sale_failed', 'message' => 'La venta no puede completarse.', 'details' => []];
            throw new MobilePosPreflightException(
                (string) ($first['code'] ?? 'sale_failed'),
                422,
                (array) ($first['details'] ?? []),
                (string) ($first['message'] ?? $first['code'] ?? 'sale_failed')
            );
        }

        $canonical = $this->canonicalPayload($payload, $preflight);
        $posResponse = $this->callCreatePos($user, $canonical);
        $status = $posResponse->getStatusCode();
        $body = $posResponse->getData(true);

        if ($status >= 400 || ! ($body['success'] ?? false)) {
            $code = $this->errorCodeFromCreatePos($body);
            throw new MobilePosPreflightException(
                $code,
                $status >= 400 ? $status : 422,
                ['pos_response' => $this->safeFailureDetails($body)],
                (string) ($body['message'] ?? $code)
            );
        }

        $sale = Sale::with('sarFiscalDocument')->findOrFail((int) $body['id']);
        $idempotent = ! empty($body['qbo_sync']) && $body['qbo_sync'] === 'skipped'
            && empty($body['updated_stock'] ?? []);

        return $this->mobileResponse(
            $sale,
            $idempotent,
            $this->stockSnapshot($preflight['context']['inventory_location_id'], $payload['lines']),
            (string) ($body['server_time'] ?? now()->toIso8601String())
        );
    }

    private function canonicalPayload(array $payload, array $preflight): array
    {
        $context = $preflight['context'];
        $linesByKey = collect($preflight['lines'])->mapWithKeys(function ($line) {
            return [$line['product_id'].':'.($line['product_variant_id'] ?? 0) => $line];
        });

        $details = collect($payload['lines'])->map(function ($input) use ($linesByKey) {
            $key = ((int) $input['product_id']).':'.((int) ($input['product_variant_id'] ?? 0));
            $line = $linesByKey->get($key);

            return [
                'product_id' => (int) $line['product_id'],
                'product_variant_id' => $line['product_variant_id'],
                'quantity' => $line['quantity'],
                'sale_unit_id' => $line['unit']['id'] ?? null,
                'Unit_price' => $line['unit_price'],
                'subtotal' => $line['subtotal'],
                'tax_percent' => $line['tax']['rate'],
                'tax_method' => $line['tax']['method'],
                'discount' => 0,
                'discount_Method' => '2',
                'price_type' => 'retail',
                'product_type' => $line['type'],
            ];
        })->values()->all();

        return [
            'sale_uuid' => (string) $payload['sale_uuid'],
            'client_id' => (int) $payload['client_id'],
            'branch_id' => (int) $context['branch_id'],
            'inventory_location_id' => (int) $context['inventory_location_id'],
            'cash_drawer_id' => (int) $context['cash_drawer_id'],
            'warehouse_id' => $context['legacy_warehouse_id'] ?: 0,
            'details' => $details,
            'payments' => collect($payload['payments'])->map(fn ($payment) => [
                'payment_method_id' => (int) $payment['payment_method_id'],
                'amount' => (string) $payment['amount'],
                'account_id' => isset($payment['account_id']) ? (int) $payment['account_id'] : null,
            ])->values()->all(),
            'TaxNet' => $preflight['totals']['tax'],
            'discount' => 0,
            'discount_Method' => '2',
            'shipping' => 0,
            'GrandTotal' => $preflight['totals']['grand_total'],
            'notes' => $payload['notes'] ?? null,
            'store_credit_vouchers' => [],
            'used_points' => 0,
            'discount_from_points' => 0,
            'promotion_code' => null,
            'promotion_subtotal' => $preflight['totals']['subtotal'],
            'promotion_item_count' => collect($details)->sum(fn ($line) => (float) $line['quantity']),
            'promotion_product_ids' => collect($details)->pluck('product_id')->values()->all(),
            'promotion_product_subtotals' => collect($details)->map(fn ($line) => [
                'product_id' => (int) $line['product_id'],
                'subtotal' => (float) $line['subtotal'],
            ])->values()->all(),
            'send_email' => false,
            'send_sms' => false,
            'kitchen_action' => 'none',
        ];
    }

    private function callCreatePos(User $user, array $payload): JsonResponse
    {
        $originalRequest = app('request');
        $request = Request::create('/api/pos/create_pos', 'POST', $payload);
        $request->setUserResolver(fn () => $user);
        $route = new Route(['POST'], 'api/pos/create_pos', [
            'uses' => 'App\Http\Controllers\PosController@CreatePOS',
            'controller' => 'App\Http\Controllers\PosController@CreatePOS',
        ]);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        app()->instance('request', $request);

        try {
            return app(PosController::class)->CreatePOS(
                $request,
                app(UserOperationalAssignmentService::class)
            );
        } finally {
            app()->instance('request', $originalRequest);
        }
    }

    private function mobileResponse(Sale $sale, bool $idempotent, array $stock, string $serverTime): array
    {
        return [
            'success' => true,
            'idempotent' => $idempotent,
            'sale' => [
                'id' => (int) $sale->id,
                'ref' => (string) $sale->Ref,
                'sale_uuid' => $sale->sale_uuid,
                'grand_total' => $this->money((float) $sale->GrandTotal),
                'payment_status' => $sale->payment_statut,
                'fiscal_number' => optional($sale->sarFiscalDocument)->fiscal_number,
                'fiscal_status' => optional($sale->sarFiscalDocument)->status,
            ],
            'stock' => $stock,
            'server_time' => $serverTime,
        ];
    }

    private function stockSnapshot(int $locationId, array $lines): array
    {
        return collect($lines)->map(function ($line) use ($locationId) {
            $variantId = isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : null;
            $stock = InventoryLocationStock::query()
                ->where('inventory_location_id', $locationId)
                ->where('product_id', (int) $line['product_id'])
                ->where('variant_key', $variantId ?: 0)
                ->first();

            $available = $stock
                ? max(0, round((float) $stock->quantity - (float) $stock->reserved_quantity, 3))
                : 0.0;

            return [
                'product_id' => (int) $line['product_id'],
                'product_variant_id' => $variantId,
                'inventory_location_id' => $locationId,
                'available_quantity' => number_format($available, 3, '.', ''),
            ];
        })->unique(fn ($row) => $row['product_id'].':'.($row['product_variant_id'] ?? 0))->values()->all();
    }

    private function errorCodeFromCreatePos(array $body): string
    {
        $message = strtolower((string) ($body['message'] ?? ''));
        if (str_contains($message, 'existencia') || str_contains($message, 'stock')) {
            return 'insufficient_stock';
        }

        if (str_contains($message, 'pago') || str_contains($message, 'paid')) {
            return 'payment_total_invalid';
        }

        return 'sale_failed';
    }

    private function safeFailureDetails(array $body): array
    {
        return array_filter([
            'code' => $body['code'] ?? null,
            'sale_id' => $body['sale_id'] ?? null,
            'sale_ref' => $body['sale_ref'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
