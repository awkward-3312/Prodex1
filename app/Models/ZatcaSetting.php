<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ZatcaSetting extends Model
{
    protected $fillable = [
        'enabled', 'auto_submit', 'environment',
        'common_name', 'organization_name', 'organization_unit',
        'solution_name', 'solution_version', 'device_serial',
        'business_category', 'invoice_types', 'crn',
        'street_name', 'building_number', 'plot_identification', 'sub_division',
        'city', 'postal_zone', 'country_code',
        'zero_tax_reason_code', 'zero_tax_reason',
        'private_key', 'csr', 'compliance_request_id',
        'ccsid_token', 'ccsid_secret', 'pcsid_token', 'pcsid_secret',
        'pcsid_requested_at', 'onboarding_status', 'compliance_results',
        'icv', 'last_invoice_hash',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'auto_submit' => 'boolean',
        'private_key' => 'encrypted',
        'ccsid_token' => 'encrypted',
        'ccsid_secret' => 'encrypted',
        'pcsid_token' => 'encrypted',
        'pcsid_secret' => 'encrypted',
        'compliance_results' => 'array',
        'icv' => 'integer',
        'pcsid_requested_at' => 'datetime',
    ];

    /**
     * Current tenant's ZATCA settings row (single-row table), created lazily.
     * Returns null when the table does not exist yet (tenant not migrated).
     */
    public static function current(): ?self
    {
        try {
            $row = static::query()->first();
            if (! $row) {
                $row = static::query()->create([
                    'environment' => config('zatca.default_environment', 'sandbox'),
                    'device_serial' => (string) Str::uuid(),
                ]);
            }

            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether Phase 2 is enabled and the unit finished onboarding
     * (has a production CSID to report/clear with).
     */
    public function isReady(): bool
    {
        return $this->enabled
            && $this->onboarding_status === 'ready'
            && ! empty($this->pcsid_token)
            && ! empty($this->pcsid_secret);
    }

    public function baseUrl(): string
    {
        $env = config('zatca.environments.'.$this->environment);

        return $env['base_url'] ?? config('zatca.environments.sandbox.base_url');
    }

    public function csrTemplate(): string
    {
        $env = config('zatca.environments.'.$this->environment);

        return $env['csr_template'] ?? 'TSTZATCA-Code-Signing';
    }
}
