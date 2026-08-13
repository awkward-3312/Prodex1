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
        Schema::create('contracts', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->string('contract_number')->unique();
            $table->integer('client_id')->index();
            $table->integer('project_id')->nullable()->index();
            $table->string('subject');
            $table->decimal('value', 16)->default(0);
            $table->string('type')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('description')->nullable();
            $table->boolean('hide_from_customer')->default(false);
            $table->string('status')->default('draft');
            $table->string('signer_name')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
