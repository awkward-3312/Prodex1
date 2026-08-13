<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('custom_field_values', function (Blueprint $table) {
            $table->foreign(['custom_field_id'])->references(['id'])->on('custom_fields')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_field_values', function (Blueprint $table) {
            $table->dropForeign('custom_field_values_custom_field_id_foreign');
        });
    }
};
