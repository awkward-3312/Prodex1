<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bank details for offline payment (stored as JSON on general_settings)
        if (! Schema::connection('central')->hasColumn('general_settings', 'bank_details')) {
            Schema::connection('central')->table('general_settings', function (Blueprint $table) {
                $table->json('bank_details')->nullable()->after('hosting_mode');
            });
        }

        // Payment proof path on pending registrations
        if (! Schema::connection('central')->hasColumn('pending_registrations', 'payment_proof_path')) {
            Schema::connection('central')->table('pending_registrations', function (Blueprint $table) {
                $table->string('payment_proof_path', 500)->nullable()->after('transaction_id');
            });
        }

        // Payment proof path on billing payments
        if (! Schema::connection('central')->hasColumn('tenant_billing_payments', 'payment_proof_path')) {
            Schema::connection('central')->table('tenant_billing_payments', function (Blueprint $table) {
                $table->string('payment_proof_path', 500)->nullable()->after('transaction_id');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('central')->table('general_settings', function (Blueprint $table) {
            $table->dropColumn('bank_details');
        });
        Schema::connection('central')->table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn('payment_proof_path');
        });
        Schema::connection('central')->table('tenant_billing_payments', function (Blueprint $table) {
            $table->dropColumn('payment_proof_path');
        });
    }
};
