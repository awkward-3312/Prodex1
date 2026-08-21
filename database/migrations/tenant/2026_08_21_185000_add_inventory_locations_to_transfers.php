<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('transfers', 'from_inventory_location_id')) {
                $table->unsignedBigInteger('from_inventory_location_id')->nullable()->index();
            }
            if (! Schema::hasColumn('transfers', 'to_inventory_location_id')) {
                $table->unsignedBigInteger('to_inventory_location_id')->nullable()->index();
            }
        });

        if (Schema::hasTable('transfer_receipts')) {
            Schema::table('transfer_receipts', function (Blueprint $table) {
                if (! Schema::hasColumn('transfer_receipts', 'inventory_location_id')) {
                    $table->unsignedBigInteger('inventory_location_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('transfer_quarantine_stock')) {
            Schema::table('transfer_quarantine_stock', function (Blueprint $table) {
                if (! Schema::hasColumn('transfer_quarantine_stock', 'inventory_location_id')) {
                    $table->unsignedBigInteger('inventory_location_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transfer_quarantine_stock') && Schema::hasColumn('transfer_quarantine_stock', 'inventory_location_id')) {
            Schema::table('transfer_quarantine_stock', fn (Blueprint $table) => $table->dropColumn('inventory_location_id'));
        }
        if (Schema::hasTable('transfer_receipts') && Schema::hasColumn('transfer_receipts', 'inventory_location_id')) {
            Schema::table('transfer_receipts', fn (Blueprint $table) => $table->dropColumn('inventory_location_id'));
        }
        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'to_inventory_location_id')) $table->dropColumn('to_inventory_location_id');
            if (Schema::hasColumn('transfers', 'from_inventory_location_id')) $table->dropColumn('from_inventory_location_id');
        });
    }
};
