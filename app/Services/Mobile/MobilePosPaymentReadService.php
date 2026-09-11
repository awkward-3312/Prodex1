<?php

namespace App\Services\Mobile;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MobilePosPaymentReadService
{
    public function paymentMethods(): Collection
    {
        return PaymentMethod::query()
            ->whereNull('deleted_at')
            ->when(Schema::hasColumn('payment_methods', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name']);
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

        return [
            'id' => (int) $method->id,
            'name' => (string) $method->name,
            'type' => $type,
            'is_cash' => $type === 'cash',
            'is_card' => $type === 'card',
            'requires_account' => true,
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
}
