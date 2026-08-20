<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'fiscal_tax_category')) {
            return;
        }

        $country = 'HN';
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'country_code')) {
            $country = strtoupper((string) (DB::table('settings')->whereNull('deleted_at')->value('country_code') ?: 'HN'));
        }

        if ($country === 'HN') {
            // Before line-level SAR taxation, Honduras POS applied the tenant's
            // order-level 15% to the whole cart. Backfill existing products to 15%
            // so moving that tax to each line preserves the amount customers were
            // already being charged. Tenants can then explicitly mark the genuine
            // exemptions/exonerations from Contabilidad > Facturación SAR.
            DB::table('products')
                ->whereNull('deleted_at')
                ->whereNull('fiscal_tax_category')
                ->update(['fiscal_tax_category' => 'taxed']);

            DB::table('products')
                ->whereNull('deleted_at')
                ->where('fiscal_tax_category', 'taxed')
                ->where(function ($query) {
                    $query->whereNull('TaxNet')->orWhere('TaxNet', '<=', 0);
                })
                ->update(['TaxNet' => 15]);
        } else {
            // Outside Honduras, preserve the product's existing rate semantics.
            DB::table('products')
                ->whereNull('deleted_at')
                ->whereNull('fiscal_tax_category')
                ->where('TaxNet', '>', 0)
                ->update(['fiscal_tax_category' => 'taxed']);

            DB::table('products')
                ->whereNull('deleted_at')
                ->whereNull('fiscal_tax_category')
                ->update(['fiscal_tax_category' => 'exempt']);
        }
    }

    public function down(): void
    {
        // Deliberately no data rollback. After tenants review fiscal classifications,
        // reverting them automatically could corrupt tax configuration.
    }
};
