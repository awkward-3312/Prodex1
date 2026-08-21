<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_receipts') || Schema::hasColumn('transfer_receipts', 'request_token')) {
            return;
        }

        Schema::table('transfer_receipts', function (Blueprint $table) {
            // Generated once by the receiving client and reused on retries. This
            // prevents a lost HTTP response / double click from applying the same
            // physical partial receipt twice.
            $table->string('request_token', 80)->nullable()->unique()->after('received_by_user_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('transfer_receipts') && Schema::hasColumn('transfer_receipts', 'request_token')) {
            Schema::table('transfer_receipts', function (Blueprint $table) {
                $table->dropUnique(['request_token']);
                $table->dropColumn('request_token');
            });
        }
    }
};
