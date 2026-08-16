<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_registers', 'sales_by_payment_method')) $table->json('sales_by_payment_method')->nullable()->after('counted_denominations');
            if (! Schema::hasColumn('cash_registers', 'expected_cash')) $table->decimal('expected_cash', 15, 2)->nullable()->after('sales_by_payment_method');
            if (! Schema::hasColumn('cash_registers', 'counted_cash')) $table->decimal('counted_cash', 15, 2)->nullable()->after('expected_cash');
            if (! Schema::hasColumn('cash_registers', 'cash_difference')) $table->decimal('cash_difference', 15, 2)->nullable()->after('counted_cash');
            if (! Schema::hasColumn('cash_registers', 'card_system_total')) $table->decimal('card_system_total', 15, 2)->nullable()->after('cash_difference');
            if (! Schema::hasColumn('cash_registers', 'card_terminal_total')) $table->decimal('card_terminal_total', 15, 2)->nullable()->after('card_system_total');
            if (! Schema::hasColumn('cash_registers', 'card_difference')) $table->decimal('card_difference', 15, 2)->nullable()->after('card_terminal_total');
            if (! Schema::hasColumn('cash_registers', 'card_batch_number')) $table->string('card_batch_number')->nullable()->after('card_difference');
            if (! Schema::hasColumn('cash_registers', 'card_reference')) $table->string('card_reference')->nullable()->after('card_batch_number');
            if (! Schema::hasColumn('cash_registers', 'card_notes')) $table->text('card_notes')->nullable()->after('card_reference');
            if (! Schema::hasColumn('cash_registers', 'transfer_total')) $table->decimal('transfer_total', 15, 2)->nullable()->after('card_notes');
            if (! Schema::hasColumn('cash_registers', 'transfers_verified')) $table->boolean('transfers_verified')->default(false)->after('transfer_total');
            if (! Schema::hasColumn('cash_registers', 'transfer_notes')) $table->text('transfer_notes')->nullable()->after('transfers_verified');
            if (! Schema::hasColumn('cash_registers', 'cash_withdrawn_at_close')) $table->decimal('cash_withdrawn_at_close', 15, 2)->nullable()->after('transfer_notes');
            if (! Schema::hasColumn('cash_registers', 'next_opening_float')) $table->decimal('next_opening_float', 15, 2)->nullable()->after('cash_withdrawn_at_close');
            if (! Schema::hasColumn('cash_registers', 'closing_snapshot')) $table->json('closing_snapshot')->nullable()->after('next_opening_float');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('cash_registers', 'sales_by_payment_method') ? 'sales_by_payment_method' : null,
            Schema::hasColumn('cash_registers', 'expected_cash') ? 'expected_cash' : null,
            Schema::hasColumn('cash_registers', 'counted_cash') ? 'counted_cash' : null,
            Schema::hasColumn('cash_registers', 'cash_difference') ? 'cash_difference' : null,
            Schema::hasColumn('cash_registers', 'card_system_total') ? 'card_system_total' : null,
            Schema::hasColumn('cash_registers', 'card_terminal_total') ? 'card_terminal_total' : null,
            Schema::hasColumn('cash_registers', 'card_difference') ? 'card_difference' : null,
            Schema::hasColumn('cash_registers', 'card_batch_number') ? 'card_batch_number' : null,
            Schema::hasColumn('cash_registers', 'card_reference') ? 'card_reference' : null,
            Schema::hasColumn('cash_registers', 'card_notes') ? 'card_notes' : null,
            Schema::hasColumn('cash_registers', 'transfer_total') ? 'transfer_total' : null,
            Schema::hasColumn('cash_registers', 'transfers_verified') ? 'transfers_verified' : null,
            Schema::hasColumn('cash_registers', 'transfer_notes') ? 'transfer_notes' : null,
            Schema::hasColumn('cash_registers', 'cash_withdrawn_at_close') ? 'cash_withdrawn_at_close' : null,
            Schema::hasColumn('cash_registers', 'next_opening_float') ? 'next_opening_float' : null,
            Schema::hasColumn('cash_registers', 'closing_snapshot') ? 'closing_snapshot' : null,
        ]));

        if (empty($columns)) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
