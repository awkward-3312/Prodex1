<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankAccountSettingsController extends Controller
{
    public function index()
    {
        $setting = GeneralSetting::instance();

        return view('central.super.settings.bank-accounts', [
            'accounts' => $this->normalizeAccounts($setting->bank_details ?? []),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'accounts' => ['present', 'array', 'max:10'],
            'accounts.*.bank_name' => ['required', 'string', 'max:255'],
            'accounts.*.account_holder' => ['required', 'string', 'max:255'],
            'accounts.*.account_number' => ['required', 'string', 'max:255'],
            'accounts.*.account_type' => ['nullable', Rule::in(['savings', 'checking'])],
            'accounts.*.currency' => ['nullable', Rule::in(['HNL', 'USD'])],
            'accounts.*.branch' => ['nullable', 'string', 'max:255'],
            'accounts.*.iban' => ['nullable', 'string', 'max:255'],
            'accounts.*.swift' => ['nullable', 'string', 'max:50'],
            'accounts.*.instructions' => ['nullable', 'string', 'max:1000'],
            'accounts.*.active' => ['nullable', 'boolean'],
        ]);

        $accounts = [];
        foreach ($validated['accounts'] as $index => $account) {
            $accounts[] = [
                'bank_name' => trim((string) $account['bank_name']),
                'account_holder' => trim((string) $account['account_holder']),
                'account_number' => trim((string) $account['account_number']),
                'account_type' => $account['account_type'] ?? 'savings',
                'currency' => $account['currency'] ?? 'HNL',
                'branch' => trim((string) ($account['branch'] ?? '')),
                'iban' => trim((string) ($account['iban'] ?? '')),
                'swift' => trim((string) ($account['swift'] ?? '')),
                'instructions' => trim((string) ($account['instructions'] ?? '')),
                'active' => (bool) ($account['active'] ?? false),
                'sort_order' => $index,
            ];
        }

        $active = collect($accounts)->where('active', true)->values();
        $primary = $active->first() ?? [];

        // The existing checkout screens read the flattened legacy keys. Keep
        // them populated from the first ACTIVE account and place all active
        // accounts in the instructions block so signup and tenant renewals can
        // immediately use every available bank without a database migration.
        $summary = $active->map(function (array $account) {
            $type = ($account['account_type'] ?? 'savings') === 'checking' ? 'Cheques' : 'Ahorros';
            $currency = $account['currency'] ?? 'HNL';
            $text = ($account['bank_name'] ?? '')
                . ' — ' . $type . ' ' . $currency
                . ' — Cuenta ' . ($account['account_number'] ?? '')
                . ' — Titular: ' . ($account['account_holder'] ?? '');

            if (! empty($account['instructions'])) {
                $text .= ' — ' . $account['instructions'];
            }

            return $text;
        })->filter()->implode(' | ');

        $setting = GeneralSetting::instance();
        $setting->bank_details = [
            'bank_name' => $primary['bank_name'] ?? '',
            'account_holder' => $primary['account_holder'] ?? '',
            'account_number' => $primary['account_number'] ?? '',
            'account_type' => $primary['account_type'] ?? '',
            'currency' => $primary['currency'] ?? '',
            'branch' => $primary['branch'] ?? '',
            'iban' => $primary['iban'] ?? '',
            'swift' => $primary['swift'] ?? '',
            'instructions' => $summary !== '' ? 'Cuentas bancarias disponibles: ' . $summary : '',
            'accounts' => $accounts,
        ];
        $setting->save();

        return redirect()
            ->route('super.settings.bank-accounts')
            ->with('success', 'Cuentas bancarias guardadas correctamente.');
    }

    private function normalizeAccounts(array $details): array
    {
        $accounts = $details['accounts'] ?? null;

        if (is_array($accounts)) {
            return collect($accounts)
                ->filter(fn ($account) => is_array($account))
                ->sortBy(fn ($account) => (int) ($account['sort_order'] ?? 0))
                ->values()
                ->map(function (array $account) {
                    return [
                        'bank_name' => (string) ($account['bank_name'] ?? ''),
                        'account_holder' => (string) ($account['account_holder'] ?? ''),
                        'account_number' => (string) ($account['account_number'] ?? ''),
                        'account_type' => in_array(($account['account_type'] ?? ''), ['savings', 'checking'], true) ? $account['account_type'] : 'savings',
                        'currency' => in_array(($account['currency'] ?? ''), ['HNL', 'USD'], true) ? $account['currency'] : 'HNL',
                        'branch' => (string) ($account['branch'] ?? ''),
                        'iban' => (string) ($account['iban'] ?? ''),
                        'swift' => (string) ($account['swift'] ?? ''),
                        'instructions' => (string) ($account['instructions'] ?? ''),
                        'active' => array_key_exists('active', $account) ? (bool) $account['active'] : true,
                    ];
                })
                ->all();
        }

        // Backwards compatibility: the previous single account becomes the
        // first active account automatically the first time this page is used.
        if (! empty($details['bank_name']) || ! empty($details['account_number']) || ! empty($details['account_holder'])) {
            return [[
                'bank_name' => (string) ($details['bank_name'] ?? ''),
                'account_holder' => (string) ($details['account_holder'] ?? ''),
                'account_number' => (string) ($details['account_number'] ?? ''),
                'account_type' => in_array(($details['account_type'] ?? ''), ['savings', 'checking'], true) ? $details['account_type'] : 'savings',
                'currency' => in_array(($details['currency'] ?? ''), ['HNL', 'USD'], true) ? $details['currency'] : 'HNL',
                'branch' => (string) ($details['branch'] ?? ''),
                'iban' => (string) ($details['iban'] ?? ''),
                'swift' => (string) ($details['swift'] ?? ''),
                'instructions' => (string) ($details['instructions'] ?? ''),
                'active' => true,
            ]];
        }

        return [];
    }
}
