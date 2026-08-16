<?php

namespace Tests\Unit;

use App\Http\Controllers\CashRegisterController;
use App\Models\CashRegister;
use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashRegisterClosingSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createCashRegisterTables();
    }

    public function test_cash_sales_use_payment_montant_without_subtracting_change(): void
    {
        $openedAt = Carbon::now()->subHour();
        $closedAt = Carbon::now();
        $user = User::create([
            'firstname' => 'Cajera',
            'username' => 'cashier',
            'email' => 'cashier@example.test',
            'password' => 'secret',
        ]);
        $warehouse = Warehouse::create(['name' => 'Principal']);
        DB::table('payment_methods')->insert([
            'id' => 2,
            'name' => 'Cash',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $register = CashRegister::create([
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'opening_balance' => 100,
            'cash_in' => 0,
            'cash_out' => 0,
            'status' => 'open',
            'opened_at' => $openedAt,
        ]);

        foreach ([14, 15] as $id) {
            DB::table('sales')->insert([
                'id' => $id,
                'date' => Carbon::today()->toDateString(),
                'Ref' => 'SL-'.$id,
                'is_pos' => 1,
                'client_id' => 1,
                'GrandTotal' => 11.50,
                'warehouse_id' => $warehouse->id,
                'user_id' => $user->id,
                'statut' => 'completed',
                'paid_amount' => 11.50,
                'payment_statut' => 'paid',
                'created_at' => $openedAt->copy()->addMinutes(10),
                'updated_at' => $openedAt->copy()->addMinutes(10),
            ]);
            PaymentSale::create([
                'sale_id' => $id,
                'date' => Carbon::today()->toDateString(),
                'Ref' => 'PAY-'.$id,
                'montant' => 11.50,
                'change' => 8.50,
                'payment_method_id' => 2,
                'user_id' => $user->id,
            ]);
        }

        $summary = (new TestableCashRegisterController)->summary($register->fresh(), $closedAt);

        $this->assertSame(23.00, $summary['cash_sales']);
        $this->assertSame(23.00, $summary['total_sales']);
        $this->assertSame(123.00, $summary['expected_cash']);
        $this->assertSame(23.00, collect($summary['sales_by_payment_method'])->firstWhere('id', 2)['total']);
    }

    private function createCashRegisterTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_methods', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('cash_registers', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('warehouse_id');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('cash_in', 15, 2)->default(0);
            $table->decimal('cash_out', 15, 2)->default(0);
            $table->string('status')->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sales', function ($table) {
            $table->increments('id');
            $table->date('date')->nullable();
            $table->string('Ref')->nullable();
            $table->boolean('is_pos')->default(false);
            $table->unsignedInteger('client_id')->nullable();
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('statut')->nullable();
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('payment_statut')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_sales', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('sale_id');
            $table->date('date')->nullable();
            $table->string('Ref')->nullable();
            $table->decimal('montant', 15, 2)->default(0);
            $table->decimal('change', 15, 2)->default(0);
            $table->unsignedInteger('payment_method_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('sale_returns', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_sale_returns', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('sale_return_id');
            $table->decimal('montant', 15, 2)->default(0);
            $table->decimal('change', 15, 2)->default(0);
            $table->unsignedInteger('payment_method_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}

class TestableCashRegisterController extends CashRegisterController
{
    public function summary(CashRegister $register, Carbon $to): array
    {
        return $this->buildClosingSummary($register, $to);
    }
}
