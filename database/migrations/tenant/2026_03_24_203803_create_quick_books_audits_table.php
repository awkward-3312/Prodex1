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
        Schema::create('quick_books_audits', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->bigIncrements('id');
            $table->integer('user_id')->nullable()->index();
            $table->integer('sale_id')->nullable()->index();
            $table->string('realm_id', 64)->nullable()->index();
            $table->string('environment', 20)->default('Development');
            $table->string('operation', 32);
            $table->string('level', 16)->default('info');
            $table->string('message', 512)->nullable();
            $table->integer('http_code')->nullable();
            $table->longText('request_payload')->nullable();
            $table->longText('response_body')->nullable();
            $table->longText('sdk_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quick_books_audits');
    }
};
