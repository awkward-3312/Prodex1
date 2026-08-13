<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PaymentGatewaySettingsController extends Controller
{
    public function index()
    {
        $definitions = PaymentGatewayFactory::getAllGatewayDefinitions();
        $gateways    = [];

        foreach ($definitions as $key => $definition) {
            $row = DB::connection('central')
                ->table('payment_gateway_settings')
                ->where('gateway', $key)
                ->first();

            if (! $row) {
                $row = (object) [
                    'id'          => null,
                    'gateway'     => $key,
                    'is_active'   => false,
                    'test_mode'   => true,
                    'credentials' => '{}',
                ];
            }

            $rawCreds    = json_decode($row->credentials ?? '{}', true) ?: [];
            $credentials = [];

            foreach ($definition['fields'] as $fieldKey => $fieldDef) {
                $val = $rawCreds[$fieldKey] ?? $rawCreds[$this->mapFieldAlias($fieldKey)] ?? '';

                if ($fieldDef['secret'] && ! empty($val) && str_starts_with($val, 'eyJ')) {
                    try {
                        $credentials[$fieldKey] = Crypt::decryptString($val);
                    } catch (\Throwable) {
                        $credentials[$fieldKey] = '';
                    }
                } else {
                    $credentials[$fieldKey] = $val;
                }
            }

            // Load currency config
            $currencyConfig = PaymentGatewayFactory::getGatewayCurrencyConfig($key);
            $dbSupportedCurrencies = ($row->id ?? null)
                ? (json_decode($row->supported_currencies ?? '[]', true) ?: [])
                : [];
            $dbDefaultCurrency = $row->default_currency ?? '';

            $gateways[$key] = [
                'definition'           => $definition,
                'setting'              => $row,
                'credentials'          => $credentials,
                'supported_currencies' => ! empty($dbSupportedCurrencies) ? $dbSupportedCurrencies : $currencyConfig['supported_currencies'],
                'default_currency'     => $dbDefaultCurrency ?: $currencyConfig['default_currency'],
                'currency_presets'     => PaymentGatewayFactory::getDefaultCurrencyPresets()[$key] ?? null,
            ];
        }

        return view('central.super.settings.payment-gateways', compact('gateways'));
    }

    public function update(Request $request, string $gateway)
    {
        $definitions = PaymentGatewayFactory::getAllGatewayDefinitions();

        if (! isset($definitions[$gateway])) {
            abort(404);
        }

        $fields  = $definitions[$gateway]['fields'];
        $input   = $request->input("gateways.{$gateway}", []);

        $isActive = ! empty($input['is_active']);
        $testMode = isset($input['test_mode']) ? (bool) $input['test_mode'] : true;

        $existingRow = DB::connection('central')
            ->table('payment_gateway_settings')
            ->where('gateway', $gateway)
            ->first();

        $existingCreds = [];
        if ($existingRow && ! empty($existingRow->credentials)) {
            $existingCreds = json_decode($existingRow->credentials, true) ?: [];
        }

        $newCreds = [];
        foreach ($fields as $fieldKey => $fieldDef) {
            $val = $input[$fieldKey] ?? '';

            if ($fieldDef['secret']) {
                if ($val === '' || $val === '••••••••') {
                    $newCreds[$fieldKey] = $existingCreds[$fieldKey]
                        ?? $existingCreds[$this->mapFieldAlias($fieldKey)]
                        ?? '';
                } else {
                    $newCreds[$fieldKey] = Crypt::encryptString($val);
                }
            } else {
                $newCreds[$fieldKey] = $val;
            }
        }

        // Parse currency configuration
        $supportedCurrenciesRaw = $input['supported_currencies'] ?? '';
        $supportedCurrencies = array_values(array_filter(
            array_map('trim', array_map('strtoupper', explode(',', $supportedCurrenciesRaw)))
        ));
        $defaultCurrency = strtoupper(trim($input['default_currency'] ?? ''));

        // Ensure default currency is in the supported list
        if ($defaultCurrency && ! empty($supportedCurrencies) && ! in_array($defaultCurrency, $supportedCurrencies, true)) {
            array_unshift($supportedCurrencies, $defaultCurrency);
        }

        $data = [
            'is_active'            => $isActive,
            'test_mode'            => $testMode,
            'credentials'          => json_encode($newCreds),
            'supported_currencies' => ! empty($supportedCurrencies) ? json_encode($supportedCurrencies) : null,
            'default_currency'     => $defaultCurrency ?: null,
            'updated_at'           => now(),
        ];

        if ($existingRow) {
            DB::connection('central')
                ->table('payment_gateway_settings')
                ->where('gateway', $gateway)
                ->update($data);
        } else {
            $data['gateway']    = $gateway;
            $data['created_at'] = now();
            DB::connection('central')
                ->table('payment_gateway_settings')
                ->insert($data);
        }

        return redirect()
            ->route('super.settings.payment-gateways', ['active' => $gateway])
            ->with('success', ucfirst($gateway) . ' settings saved successfully.');
    }

    /**
     * Map between standard field keys and legacy DB column names.
     */
    protected function mapFieldAlias(string $fieldKey): string
    {
        return match ($fieldKey) {
            'publishable_key' => 'public_key',
            'public_key'      => 'publishable_key',
            default           => $fieldKey,
        };
    }
}
