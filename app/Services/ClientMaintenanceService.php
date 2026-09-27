<?php
namespace App\Services;

use App\Models\Client;
use App\Models\EcommerceClient;
use App\Models\Setting;
use App\Services\TenantTaxConfigResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Customer maintenance authority shared by web and Mobile. Financial fields remain caller-owned. */
class ClientMaintenanceService
{
    public const FIELDS = ['firstname', 'lastname', 'name', 'adresse', 'phone', 'email', 'country', 'city', 'state', 'zip', 'tax_number'];
    public function resolveTenantTaxConfig(): array
    {
        $setting = Setting::where('deleted_at', '=', null)->first();
        $tenantCountry = null;

        try {
            $tenantCountry = function_exists('tenant') && tenant() ? (tenant()->country_code ?? null) : null;
        } catch (\Throwable $e) {
            $tenantCountry = null;
        }

        return TenantTaxConfigResolver::resolve($setting, $tenantCountry);
    }

    public function normalizeClientFiscalInput(Request $request, array $taxConfig): void
    {
        $countryCode = strtoupper((string) ($taxConfig['country_code'] ?? ''));

        if ($countryCode !== 'HN') {
            return;
        }

        $taxNumber = preg_replace('/\D+/', '', (string) $request->input('tax_number', ''));
        $name = trim((string) $request->input('name', ''));

        $request->merge([
            'tax_number' => $taxNumber,
            'name' => $taxNumber === '' && $name === '' ? 'Cliente Final' : $name,
            'country' => $request->filled('country') ? $request->input('country') : 'Honduras',
        ]);
    }

    public function validateClientFiscalInput(Request $request, array $taxConfig): void
    {
        $countryCode = strtoupper((string) ($taxConfig['country_code'] ?? ''));

        if ($countryCode !== 'HN') {
            return;
        }

        $taxNumber = (string) $request->input('tax_number', '');
        if ($taxNumber === '') {
            return;
        }

        Validator::make($request->all(), [
            'tax_number' => [
                'required',
                'digits:14',
                function ($attribute, $value, $fail) {
                    if (preg_match('/^(\d)\1{13}$/', (string) $value)) {
                        $fail('El RTN no es válido.');
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (mb_strtolower(trim((string) $value), 'UTF-8') === 'cliente final') {
                        $fail('Ingrese el nombre o razón social asociado al RTN.');
                    }
                },
            ],
        ], [
            'tax_number.digits' => 'El RTN debe contener 14 dígitos.',
            'name.required' => 'Ingrese el nombre o razón social asociado al RTN.',
        ])->validate();
    }


    public function validateCreate(Request $request): void
    {
        $request->validate([
            'name' => 'required',
            'firstname' => ['nullable', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable', 'email', 'max:255',
                // Ensure email is unique in clients table (exclude soft-deleted)
                Rule::unique('clients', 'email')->whereNull('deleted_at'),
                // Ensure email is unique in ecommerce_clients table (exclude soft-deleted)
                Rule::unique('ecommerce_clients', 'email')->whereNull('deleted_at'),
            ],
        ]);
    }
    public function validateUpdate(Request $request, $id): void
    {
        // Get existing ecommerce_client id if it exists (for ignoring in validation)
        $existingEcommerceClient = EcommerceClient::where('client_id', $id)
            ->whereNull('deleted_at')
            ->first();
        $ecommerceClientId = $existingEcommerceClient ? $existingEcommerceClient->id : null;

        // Validate input for both Client and EcommerceClient
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'firstname' => ['nullable', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => [
                'nullable', 'email', 'max:255',
                // Ensure email is unique in clients table (ignore current client, exclude soft-deleted)
                Rule::unique('clients', 'email')
                    ->ignore($id)
                    ->whereNull('deleted_at'),
                // Ensure email is unique in ecommerce_clients table (ignore current ecommerce_client if exists, exclude soft-deleted)
                Rule::unique('ecommerce_clients', 'email')
                    ->ignore($ecommerceClientId)
                    ->whereNull('deleted_at'),
            ],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'zip' => ['nullable', 'string', 'max:20'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],

            // EcommerceClient-specific (optional)
            'username' => [
                'nullable', 'string', 'max:100',
                Rule::unique('ecommerce_clients', 'username')->ignore($id, 'client_id'),
            ],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:6'],

            // flags
            'is_royalty_eligible' => ['nullable'],
        ]);


    }
    public function nextCode(): int
    {
        $last = DB::table('clients')->latest('id')->first();
        return $last ? $last->code + 1 : 1;
    }
    public function create(Request $request): Client
    {
        return Client::create(array_merge(array_fill_keys(self::FIELDS, null), $request->only(self::FIELDS), [
            'code' => $this->nextCode(),
            'is_royalty_eligible' => in_array($request->input('is_royalty_eligible'), ['1', 'true'], false) ? 1 : 0,
            'opening_balance' => $request->input('opening_balance') ?? 0,
            'credit_limit' => $request->input('credit_limit') ?? 0,
        ]));
    }
    public function updateMaintenance(Client $client, Request $request): void
    {
        $client->fill($request->only(self::FIELDS))->save();
    }
}
