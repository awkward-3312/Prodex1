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
        Schema::create('payrolls', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('user_id')->index('payrolls_user_id');
            $table->string('Ref', 192);
            $table->date('date');
            $table->integer('employee_id')->index('payrolls_employee_id');
            $table->integer('account_id')->nullable()->index('payrolls_account_id');
            $table->float('amount');
            $table->integer('payment_method_id')->nullable()->index('payrolls_payment_method_id_foreign');
            $table->string('payment_status', 192);
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
