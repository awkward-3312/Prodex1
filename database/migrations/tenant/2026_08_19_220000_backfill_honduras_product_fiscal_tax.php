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
            // Historically the HN POS charged the tenant's 15% order tax on top of
            // products whose own TaxNet was commonly zero. When moving that tax to
            // the line we must make those legacy prices EXCLUSIVE, otherwise a row
            // that happened to have tax_method=2 would suddenly become 15% cheaper.
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
                ->update([
                    'TaxNet' => 15,
                    'tax_method' => '1',
                ]);
        } else {
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
        // Deliberately no data rollback. After a tenant reviews fiscal categories,
        // reverting them automatically could corrupt the tax configuration.
    }
};
