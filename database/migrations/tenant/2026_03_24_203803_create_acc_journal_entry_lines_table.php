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
        Schema::create('acc_journal_entry_lines', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('journal_entry_id')->index();
            $table->integer('coa_id')->nullable()->index();
            $table->integer('account_id')->nullable()->index();
            $table->decimal('debit', 20, 6)->default(0);
            $table->decimal('credit', 20, 6)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acc_journal_entry_lines');
    }
};
