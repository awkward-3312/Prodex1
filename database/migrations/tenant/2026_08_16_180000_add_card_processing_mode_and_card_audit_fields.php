<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_settings', 'card_processing_mode')) {
                $table->string('card_processing_mode', 50)
                    ->default('external_terminal')
                    ->after('stripe_secret');
            }
        });

        Schema::table('payment_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_sales', 'card_processor')) {
                $table->string('card_processor', 50)->nullable()->after('account_id');
            }
            if (! Schema::hasColumn('payment_sales', 'card_reference')) {
                $table->string('card_reference')->nullable()->after('card_processor');
            }
            if (! Schema::hasColumn('payment_sales', 'authorization_code')) {
                $table->string('authorization_code')->nullable()->after('card_reference');
            }
            if (! Schema::hasColumn('payment_sales', 'card_last4')) {
                $table->string('card_last4', 4)->nullable()->after('authorization_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_sales', function (Blueprint $table) {
            foreach (['card_last4', 'authorization_code', 'card_reference', 'card_processor'] as $column) {
                if (Schema::hasColumn('payment_sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('payment_settings', function (Blueprint $table) {
            if (Schema::hasColumn('payment_settings', 'card_processing_mode')) {
                $table->dropColumn('card_processing_mode');
            }
        });
    }
};
