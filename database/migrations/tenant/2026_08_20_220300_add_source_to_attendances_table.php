<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('source', 40)->default('manual')->after('status')->index();
            $table->string('source_reference')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'source_reference']);
        });
    }
};
