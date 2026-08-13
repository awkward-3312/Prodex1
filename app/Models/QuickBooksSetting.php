<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickBooksSetting extends Model
{
    protected $fillable = [
        'client_id', 'client_secret', 'redirect_uri', 'environment',
        'realm_id', 'income_account_name',
    ];

    /**
     * Current tenant's QuickBooks settings, falling back to the central
     * services.quickbooks config for values not set by the tenant.
     * Keys match the settings form: client_id, client_secret, redirect,
     * env, realm_id, income_account_name.
     */
    public static function values(): array
    {
        $row = null;
        try {
            $row = static::query()->first();
        } catch (\Throwable $e) {
            // Table may not exist yet on tenants that haven't run the migration.
        }

        $cfg = (array) config('services.quickbooks', []);

        return [
            'client_id' => $row->client_id ?? ($cfg['client_id'] ?? null),
            'client_secret' => $row->client_secret ?? ($cfg['client_secret'] ?? null),
            'redirect' => $row->redirect_uri ?? ($cfg['redirect'] ?? null),
            'env' => $row->environment ?? ($cfg['env'] ?? 'Development'),
            'realm_id' => $row->realm_id ?? ($cfg['realm_id'] ?? null),
            'income_account_name' => $row->income_account_name ?? ($cfg['income_account_name'] ?? null),
        ];
    }
}
