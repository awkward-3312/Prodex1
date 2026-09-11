<?php

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobilePosPreflightException;
use App\Models\Account;
use App\Models\Client;
use App\Models\InventoryLocationStock;
use App\Models\PaymentMethod;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductBatchLocationStock;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class MobilePosSalePreflightService
{
    public function __construct(
        private PosOperationalContextReadService $contextReader,
        private MobilePosProductReadService $reader,
        private MobilePosPaymentReadService $payments
    ) {}

    public function preflight(User $user, array $payload): array
    {
        $context = $this->resolvedContext($user);
        $client = $this->client((int) $payload['client_id']);
        $lines = [];
        $errors = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach (array_values($payload['lines']) as $index => $line) {
            $lineResult = $this->line($line, $index, $context['inventory_location_id']);
            $lines[] = $lineResult['line'];
            $subtotal = round($subtotal + (float) $lineResult['line']['subtotal'], 2);
            $taxTotal = round($taxTotal + (float) $lineResult['line']['tax']['amount'], 2);

            foreach ($lineResult['errors'] as $error) {
                $errors[] = $error;
            }
        }

        $grandTotal = round($subtotal, 2);
        $paymentResult = $this->paymentPreflight((array) ($payload['payment_intent'] ?? []), $grandTotal);
        $errors = array_merge($errors, $paymentResult['errors']);

        return [
            'can_submit' => empty($errors),
            'context' => $context,
            'client' => [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'phone' => $client->phone,
                'tax_number' => $client->tax_number,
            ],
            'lines' => $lines,
            'totals' => [
                'subtotal' => $this->money($subtotal),
                'discount' => '0.00',
                'tax' => $this->money($taxTotal),
                'shipping' => '0.00',
                'grand_total' => $this->money($grandTotal),
            ],
            'payments' => [
                'requested' => $paymentResult['requested'],
                'applied_total' => $this->money($paymentResult['applied_total']),
                'change' => $this->money($paymentResult['change']),
                'remaining_due' => $this->money(max(0, $grandTotal - $paymentResult['applied_total'])),
            ],
            'requirements' => [
                'sale_uuid_required' => true,
            ],
            'errors' => $errors,
        ];
    }

    private function resolvedContext(User $user): array
    {
        $context = $this->contextReader->forUser($user);
        if (! ($context['ready_for_location_pos'] ?? false)) {
            throw new MobilePosPreflightException('invalid_operational_context', 422);
        }

        $effective = $context['effective'];

        return [
            'branch_id' => (int) $effective['branch_id'],
            'inventory_location_id' => (int) $effective['inventory_location_id'],
            'cash_drawer_id' => (int) $effective['cash_drawer_id'],
            'legacy_warehouse_id' => $effective['legacy_warehouse_id'] ? (int) $effective['legacy_warehouse_id'] : null,
        ];
    }

    private function client(int $clientId): Client
    {
        $client = Client::whereNull('deleted_at')->find($clientId);
        if (! $client) {
            throw new MobilePosPreflightException('invalid_client', 422);
        }

        return $client;
    }

    private function line(array $input, int $index, int $locationId): array
    {
        $product = Product::with('unitSale')
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->where('not_selling', 0)
            ->find((int) $input['product_id']);

        if (! $product) {
            throw new MobilePosPreflightException('product_not_found', 422, ['line' => $index]);
        }

        $variant = null;
        if (! empty($input['product_variant_id'])) {
            $variant = ProductVariant::whereNull('deleted_at')
                ->where('product_id', $product->id)
                ->find((int) $input['product_variant_id']);

            if (! $variant) {
                throw new MobilePosPreflightException('variant_not_found', 422, ['line' => $index]);
            }
        }

        if ((string) $product->type === 'is_combo') {
            return $this->blockedLine($product, $variant, $locationId, $input['quantity'], $index, 'combo_not_supported');
        }

        if ($this->isVariantProduct($product) && ! $variant) {
            throw new MobilePosPreflightException('variant_not_found', 422, ['line' => $index]);
        }

        $quantity = $this->quantity((string) $input['quantity'], $index);
        $read = $this->reader->item($product, $variant, $locationId);
        $basePrice = (float) ($variant?->price ?? $product->price ?? 0);
        $taxPercent = max(0.0, (float) ($product->TaxNet ?? 0));
        $taxMethod = (string) ($product->tax_method ?? '1');
        $unitTax = $taxPercent > 0 ? $basePrice * $taxPercent / 100 : 0.0;

        if ($taxMethod === '1') {
            $unitNet = $basePrice;
            $unitTotal = round($basePrice + $unitTax, 6);
        } else {
            $unitTotal = $basePrice;
            $unitNet = round($basePrice - $unitTax, 6);
        }

        $lineTax = round($quantity * $unitTax, 2);
        $lineTotal = round($quantity * $unitTotal, 2);
        $inventory = $this->inventory($product, $variant, $locationId, $quantity);
        $requirements = $this->requirements($product, $variant, $locationId, $quantity);
        $errors = [];

        if (! $inventory['can_fulfill']) {
            $errors[] = $this->lineError($index, 'insufficient_stock', [
                'requested_quantity' => $this->qty($quantity),
                'available_quantity' => $inventory['available_quantity'],
            ]);
        }

        if ($requirements['serials_required']) {
            $errors[] = $this->lineError($index, 'serial_selection_required', [
                'serial_quantity' => $requirements['serial_quantity'],
            ]);
        }

        if ($requirements['batch_required']) {
            $errors[] = $this->lineError($index, 'batch_selection_required', [
                'batch_auto_assignable' => $requirements['batch_auto_assignable'],
            ]);
        }

        return [
            'line' => [
                'product_id' => (int) $product->id,
                'product_variant_id' => $variant ? (int) $variant->id : null,
                'display_name' => $read['display_name'],
                'type' => (string) $product->type,
                'quantity' => $this->qty($quantity),
                'unit' => $product->unitSale ? [
                    'id' => (int) $product->unitSale->id,
                    'name' => (string) $product->unitSale->ShortName,
                ] : null,
                'unit_price' => $this->money($basePrice),
                'net_unit_price' => $this->money($unitNet),
                'subtotal' => $this->money($lineTotal),
                'tax' => [
                    'rate' => $this->money($taxPercent),
                    'amount' => $this->money($lineTax),
                    'method' => $taxMethod,
                ],
                'inventory' => $inventory,
                'requirements' => $requirements,
            ],
            'errors' => $errors,
        ];
    }

    private function blockedLine(Product $product, ?ProductVariant $variant, int $locationId, string $quantity, int $index, string $code): array
    {
        $read = $this->reader->item($product, $variant, $locationId);

        return [
            'line' => [
                'product_id' => (int) $product->id,
                'product_variant_id' => $variant ? (int) $variant->id : null,
                'display_name' => $read['display_name'],
                'type' => (string) $product->type,
                'quantity' => $this->qty((float) $quantity),
                'unit_price' => '0.00',
                'net_unit_price' => '0.00',
                'subtotal' => '0.00',
                'tax' => [
                    'rate' => '0.00',
                    'amount' => '0.00',
                    'method' => (string) ($product->tax_method ?? '1'),
                ],
                'inventory' => [
                    'location_id' => $locationId,
                    'available_quantity' => '0.000',
                    'requested_quantity' => $this->qty((float) $quantity),
                    'manage_stock' => true,
                    'can_fulfill' => false,
                    'overselling_allowed' => false,
                ],
                'requirements' => [
                    'serials_required' => false,
                    'serial_quantity' => 0,
                    'batch_required' => false,
                    'batch_auto_assignable' => false,
                    'packs_supported' => false,
                ],
            ],
            'errors' => [$this->lineError($index, $code)],
        ];
    }

    private function quantity(string $value, int $index): float
    {
        $quantity = (float) $value;
        if ($quantity <= 0) {
            throw new MobilePosPreflightException('invalid_quantity', 422, ['line' => $index]);
        }

        return round($quantity, 3);
    }

    private function inventory(Product $product, ?ProductVariant $variant, int $locationId, float $quantity): array
    {
        $isService = (string) $product->type === 'is_service';
        $stock = $isService ? null : InventoryLocationStock::query()
            ->where('inventory_location_id', $locationId)
            ->where('product_id', (int) $product->id)
            ->where('variant_key', $variant ? (int) $variant->id : 0)
            ->first();

        $manageStock = $isService ? false : (bool) ($stock->manage_stock ?? true);
        $physical = $isService ? 0.0 : round((float) ($stock->quantity ?? 0), 3);
        $reserved = $isService ? 0.0 : round((float) ($stock->reserved_quantity ?? 0), 3);
        $available = $isService ? 0.0 : round(max(0, $physical - $reserved), 3);

        return [
            'location_id' => $locationId,
            'available_quantity' => $this->qty($available),
            'requested_quantity' => $this->qty($quantity),
            'manage_stock' => $manageStock,
            'can_fulfill' => $isService || ! $manageStock || $available + 0.0005 >= $quantity,
            'overselling_allowed' => false,
        ];
    }

    private function requirements(Product $product, ?ProductVariant $variant, int $locationId, float $quantity): array
    {
        $serialsRequired = (bool) ($product->is_imei ?? false);
        $batchRequired = (bool) ($product->is_batch_tracked ?? false);

        return [
            'serials_required' => $serialsRequired,
            'serial_quantity' => $serialsRequired ? (int) round($quantity) : 0,
            'batch_required' => $batchRequired,
            'batch_auto_assignable' => $batchRequired
                ? $this->batchAvailable($product, $variant, $locationId, $quantity)
                : false,
            'packs_supported' => false,
        ];
    }

    private function batchAvailable(Product $product, ?ProductVariant $variant, int $locationId, float $quantity): bool
    {
        if (! Schema::hasTable('product_batch_location_stocks')) {
            return false;
        }

        $available = ProductBatchLocationStock::query()
            ->where('inventory_location_id', $locationId)
            ->whereHas('batch', function ($query) use ($product, $variant) {
                $query->where('product_id', (int) $product->id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at');

                $variant
                    ? $query->where('product_variant_id', (int) $variant->id)
                    : $query->whereNull('product_variant_id');
            })
            ->get()
            ->sum(fn ($row) => max(0, (float) $row->quantity - (float) $row->reserved_quantity));

        return round($available, 3) + 0.0005 >= $quantity;
    }

    private function paymentPreflight(array $input, float $grandTotal): array
    {
        $remaining = round(max(0, $grandTotal), 2);
        $requested = [];
        $errors = [];
        $appliedTotal = 0.0;
        $changeTotal = 0.0;

        foreach (array_values($input) as $index => $payment) {
            $method = PaymentMethod::whereNull('deleted_at')->find((int) ($payment['payment_method_id'] ?? 0));
            if (! $method) {
                throw new MobilePosPreflightException('invalid_payment_method', 422, ['payment' => $index]);
            }

            $account = null;
            if (! empty($payment['account_id'])) {
                $account = Account::whereNull('deleted_at')->find((int) $payment['account_id']);
                if (! $account) {
                    throw new MobilePosPreflightException('invalid_account', 422, ['payment' => $index]);
                }
            }

            $methodInfo = $this->payments->formatMethod($method);
            if ($methodInfo['is_card'] && $this->cardProcessingMode() === PaymentSetting::CARD_MODE_STRIPE) {
                $errors[] = [
                    'code' => 'unsupported_payment_method',
                    'message' => 'El metodo de tarjeta Stripe no esta soportado en Mobile MVP.',
                    'details' => ['payment' => $index],
                ];
            }

            $tendered = round((float) ($payment['amount'] ?? 0), 2);
            $applied = min($tendered, $remaining);
            $change = 0.0;

            if ($tendered > $remaining + 0.005) {
                if (! $methodInfo['is_cash']) {
                    $errors[] = [
                        'code' => 'payment_total_invalid',
                        'message' => 'El pago no efectivo excede el saldo pendiente.',
                        'details' => ['payment' => $index],
                    ];
                } else {
                    $change = round($tendered - $remaining, 2);
                }
            }

            $requested[] = [
                'payment_method_id' => (int) $method->id,
                'payment_method_name' => (string) $method->name,
                'type' => $methodInfo['type'],
                'account_id' => $account ? (int) $account->id : null,
                'amount_received' => $this->money($tendered),
                'amount_applied' => $this->money($applied),
                'change' => $this->money($change),
            ];

            $appliedTotal = round($appliedTotal + $applied, 2);
            $changeTotal = round($changeTotal + $change, 2);
            $remaining = round(max(0, $remaining - $applied), 2);
        }

        if ($grandTotal > 0 && $appliedTotal + 0.005 < $grandTotal) {
            $errors[] = [
                'code' => 'payment_total_invalid',
                'message' => 'El total pagado no cubre el total de la venta.',
                'details' => ['remaining_due' => $this->money($grandTotal - $appliedTotal)],
            ];
        }

        return [
            'requested' => $requested,
            'applied_total' => $appliedTotal,
            'change' => $changeTotal,
            'errors' => $errors,
        ];
    }

    private function isVariantProduct(Product $product): bool
    {
        return (int) ($product->is_variant ?? 0) === 1 || (string) $product->type === 'is_variant';
    }

    private function lineError(int $index, string $code, array $details = []): array
    {
        return [
            'code' => $code,
            'message' => $code,
            'details' => array_merge(['line' => $index], $details),
        ];
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private function qty(float $value): string
    {
        return number_format(round($value, 3), 3, '.', '');
    }

    private function cardProcessingMode(): string
    {
        if (! Schema::hasTable('payment_settings')) {
            return PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL;
        }

        return PaymentSetting::current()->effectiveCardProcessingMode();
    }
}
