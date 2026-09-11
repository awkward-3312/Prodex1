<?php

namespace App\Services\Mobile;

use App\Models\Client;
use App\Models\Sale;
use App\Models\SarFiscalProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\TenantTaxConfigResolver;
use Illuminate\Support\Facades\Schema;

class MobilePosCheckoutContextService
{
    public function __construct(
        private PosOperationalContextReadService $contextReader,
        private MobilePosPaymentReadService $payments
    ) {}

    public function forUser(User $user): array
    {
        $context = $this->contextReader->forUser($user);
        $settings = Setting::with('Currency')->whereNull('deleted_at')->first();
        $ready = (bool) ($context['ready_for_location_pos'] ?? false);

        return [
            'operational_context' => $this->formatOperationalContext($context),
            'customer' => [
                'default' => $this->defaultClient($settings),
            ],
            'payment_methods' => $this->payments->formattedPaymentMethods(),
            'accounts' => $this->payments->formattedAccounts(),
            'tax' => $this->tax($settings),
            'currency' => $this->currency($settings),
            'pricing' => [
                'manual_price' => false,
                'line_discount' => false,
                'sale_discount' => false,
                'price_source' => 'server',
                'price_decimals' => $this->priceDecimals($settings),
            ],
            'defaults' => [
                'payment_method_id' => $this->payments->defaultPaymentMethodId($settings),
                'account_id' => $this->payments->defaultAccountId($settings),
            ],
            'capabilities' => $this->capabilities($ready),
        ];
    }

    private function formatOperationalContext(array $context): array
    {
        $effective = $context['effective'] ?? [];
        $branch = $this->findById($context['branches'] ?? collect(), $effective['branch_id'] ?? null);
        $location = $this->findById($context['inventory_locations'] ?? collect(), $effective['inventory_location_id'] ?? null);
        $drawer = $this->findById($context['cash_drawers'] ?? collect(), $effective['cash_drawer_id'] ?? null);
        $ready = (bool) ($context['ready_for_location_pos'] ?? false);

        return [
            'branch' => $branch ? [
                'id' => (int) $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ] : null,
            'inventory_location' => $location ? [
                'id' => (int) $location->id,
                'branch_id' => (int) $location->branch_id,
                'code' => $location->code,
                'name' => $location->name,
                'type' => $location->type,
            ] : null,
            'cash_drawer' => $drawer ? [
                'id' => (int) $drawer->id,
                'branch_id' => (int) $drawer->branch_id,
                'inventory_location_id' => (int) $drawer->inventory_location_id,
                'code' => $drawer->code,
                'name' => $drawer->name,
            ] : null,
            'legacy_warehouse_id' => $effective['legacy_warehouse_id'] ? (int) $effective['legacy_warehouse_id'] : null,
            'ready_for_location_pos' => $ready,
            'reason' => $ready ? null : 'operational_context_incomplete',
        ];
    }

    private function defaultClient(?Setting $settings): ?array
    {
        if (! $settings?->client_id) {
            return null;
        }

        $client = Client::whereNull('deleted_at')->find((int) $settings->client_id);

        return $client ? $this->formatClient($client) : null;
    }

    private function formatClient(Client $client): array
    {
        return [
            'id' => (int) $client->id,
            'name' => (string) $client->name,
            'phone' => $client->phone,
            'tax_number' => $client->tax_number,
        ];
    }

    private function tax(?Setting $settings): array
    {
        $tenantCountry = null;
        try {
            $tenantCountry = function_exists('tenant') && tenant() ? (tenant()->country_code ?? null) : null;
        } catch (\Throwable $e) {
            $tenantCountry = null;
        }

        $tax = $settings
            ? TenantTaxConfigResolver::resolve($settings, $tenantCountry)
            : TenantTaxConfigResolver::defaultForCountry($tenantCountry ?: 'HN');

        return [
            'country' => strtoupper((string) ($tax['country_code'] ?? 'HN')),
            'tax_name' => $tax['tax_name'] ?? null,
            'tax_rate' => $this->money((float) ($tax['tax_rate'] ?? 0)),
            'tax_rates' => array_values($tax['tax_rates'] ?? []),
            'supports_line_tax' => (bool) ($tax['supports_line_tax'] ?? false),
            'customer_tax_id_label' => $tax['customer_tax_id_label'] ?? null,
            'fiscal_enabled' => $this->fiscalEnabled($settings),
            'decimals' => $this->priceDecimals($settings),
        ];
    }

    private function fiscalEnabled(?Setting $settings): bool
    {
        if ((bool) ($settings?->zatca_enabled ?? false)) {
            return true;
        }

        return Schema::hasTable('sar_fiscal_profiles')
            && SarFiscalProfile::query()->where('enabled', true)->exists();
    }

    private function currency(?Setting $settings): array
    {
        $currency = $settings?->Currency;

        return [
            'code' => strtoupper((string) ($currency?->code ?? $settings?->currency_code ?? 'HNL')),
            'symbol' => (string) ($currency?->symbol ?? $settings?->currency_symbol ?? 'L'),
            'price_decimals' => $this->priceDecimals($settings),
        ];
    }

    private function capabilities(bool $ready): array
    {
        return [
            'can_create_sale' => $ready,
            'reason' => $ready ? null : 'operational_context_incomplete',
            'manual_price' => false,
            'line_discount' => false,
            'sale_discount' => false,
            'promotions' => false,
            'points' => false,
            'store_credit' => false,
            'packs' => false,
            'weighted_quantity' => true,
            'combos' => false,
            'batches' => Schema::hasTable('product_batch_location_stocks'),
            'serials' => Schema::hasTable('product_serials'),
            'stripe' => false,
            'external_card' => true,
            'mixed_payments' => true,
            'overselling' => false,
            'sale_uuid_required' => true,
            'supported_product_types' => ['is_single', 'is_variant', 'is_service'],
        ];
    }

    private function findById($items, $id)
    {
        return $id ? $items->firstWhere('id', (int) $id) : null;
    }

    private function priceDecimals(?Setting $settings): int
    {
        return (bool) ($settings?->enable_3_decimal_pricing ?? false) ? 3 : 2;
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
