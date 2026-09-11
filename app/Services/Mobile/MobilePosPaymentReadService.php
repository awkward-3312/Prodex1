<?php

namespace App\Services\Mobile;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\PaymentSetting;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MobilePosPaymentReadService
{
    public function paymentMethods(): Collection
    {
        $columns = ['id', 'name'];
        foreach (['requires_account', 'account_required'] as $column) {
            if (Schema::hasColumn('payment_methods', $column)) {
                $columns[] = $column;
            }
        }

        return PaymentMethod::query()
            ->whereNull('deleted_at')
            ->when(Schema::hasColumn('payment_methods', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get($columns);
    }

    public function formattedPaymentMethods(): array
    {
        return $this->paymentMethods()
            ->map(fn (PaymentMethod $method) => $this->formatMethod($method))
            ->values()
            ->all();
    }

    public function accounts(): Collection
    {
        return Account::query()
            ->whereNull('deleted_at')
            ->orderBy('account_name')
            ->get(['id', 'account_name']);
    }

    public function formattedAccounts(): array
    {
        return $this->accounts()
            ->map(fn (Account $account) => [
                'id' => (int) $account->id,
                'name' => (string) $account->account_name,
                'is_active' => true,
            ])
            ->values()
            ->all();
    }

    public function defaultAccountId(?Setting $settings = null): ?int
    {
        $settings = $settings ?: Setting::whereNull('deleted_at')->first();
        $accountId = $settings?->default_account_id ? (int) $settings->default_account_id : null;

        return $accountId && Account::whereNull('deleted_at')->whereKey($accountId)->exists()
            ? $accountId
            : null;
    }

    public function defaultPaymentMethodId(?Setting $settings = null): ?int
    {
        $settings = $settings ?: Setting::whereNull('deleted_at')->first();
        $methodId = $settings?->default_payment_method_id ? (int) $settings->default_payment_method_id : null;

        return $methodId && PaymentMethod::whereNull('deleted_at')->whereKey($methodId)->exists()
            ? $methodId
            : null;
    }

    public function formatMethod(PaymentMethod $method): array
    {
        $type = $this->typeFor($method);
        $requiresAccount = $this->requiresAccount($method);
        $isStripeCard = $type === 'card' && $this->cardProcessingMode() === PaymentSetting::CARD_MODE_STRIPE;
        $hasAccounts = $this->hasAccounts();

        return [
            'id' => (int) $method->id,
            'name' => (string) $method->name,
            'type' => $type,
            'is_cash' => $type === 'cash',
            'is_card' => $type === 'card',
            'requires_account' => $requiresAccount,
            'is_supported' => true,
            'is_available' => ! $isStripeCard && (! $requiresAccount || $hasAccounts),
            'supports_change' => $type === 'cash',
            'stripe_supported' => false,
        ];
    }

    public function typeFor(PaymentMethod $method): string
    {
        $name = strtolower(trim((string) $method->name));

        if (str_contains($name, 'cash') || str_contains($name, 'efectivo')) {
            return 'cash';
        }

        if ((string) $method->id === '1'
            || str_contains($name, 'card')
            || str_contains($name, 'tarjeta')
            || str_contains($name, 'credit')
            || str_contains($name, 'debit')
            || str_contains($name, 'tpe')) {
            return 'card';
        }

        return 'other';
    }

    public function requiresAccount(PaymentMethod $method): bool
    {
        foreach (['requires_account', 'account_required'] as $column) {
            if (Schema::hasColumn('payment_methods', $column)) {
                return (bool) $method->{$column};
            }
        }

        return false;
    }

    public function hasAccounts(): bool
    {
        return Account::whereNull('deleted_at')->exists();
    }

    private function cardProcessingMode(): string
    {
        if (! Schema::hasTable('payment_settings')) {
            return PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL;
        }

        return PaymentSetting::current()->effectiveCardProcessingMode();
    }
}
