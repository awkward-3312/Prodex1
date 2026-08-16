<?php

namespace Tests\Unit;

use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\PosController;
use App\Models\CashRegister;
use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\StoreCreditVoucher;
use App\Models\StoreCreditVoucherTransaction;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class StoreCreditMixedPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createStoreCreditPaymentTables();
    }

    public function test_voucher_cash_over_tender_completes_with_cash_applied_only_to_remaining_due(): void
    {
        $result = $this->simulateVoucherSale(30, 19, [
            ['amount' => 20, 'payment_method_id' => 2],
        ]);

        $sale = $result['sale']->fresh();
        $payment = PaymentSale::where('sale_id', $sale->id)->first();
        $voucher = $result['voucher']->fresh();
        $transaction = StoreCreditVoucherTransaction::where('sale_id', $sale->id)->first();
        $summary = (new StoreCreditTestableCashRegisterController)->summary($result['register']->fresh(), Carbon::now());

        $this->assertSame(30.00, (float) $sale->paid_amount);
        $this->assertSame('paid', $sale->payment_statut);
        $this->assertSame(19.00, (float) $transaction->amount);
        $this->assertSame(11.00, (float) $payment->montant);
        $this->assertSame(9.00, (float) $payment->change);
        $this->assertSame(0.00, (float) $voucher->remaining_balance);
        $this->assertSame(11.00, $summary['cash_sales']);
        $this->assertSame(19.00, $summary['store_credit_applied']);
    }

    public function test_voucher_cash_exact_amount_marks_sale_paid(): void
    {
        $result = $this->simulateVoucherSale(30, 19, [
            ['amount' => 11, 'payment_method_id' => 2],
        ]);

        $payment = PaymentSale::where('sale_id', $result['sale']->id)->first();

        $this->assertSame(30.00, (float) $result['sale']->fresh()->paid_amount);
        $this->assertSame(11.00, (float) $payment->montant);
        $this->assertSame(0.00, (float) $payment->change);
    }

    public function test_voucher_card_remaining_due_marks_sale_paid(): void
    {
        $result = $this->simulateVoucherSale(30, 19, [
            ['amount' => 11, 'payment_method_id' => 1],
        ]);

        $payment = PaymentSale::where('sale_id', $result['sale']->id)->first();

        $this->assertSame(30.00, (float) $result['sale']->fresh()->paid_amount);
        $this->assertSame(11.00, (float) $payment->montant);
        $this->assertSame(0.00, (float) $payment->change);
    }

    public function test_voucher_cash_and_card_split_marks_sale_paid(): void
    {
        $result = $this->simulateVoucherSale(30, 10, [
            ['amount' => 10, 'payment_method_id' => 2],
            ['amount' => 10, 'payment_method_id' => 1],
        ]);

        $payments = PaymentSale::where('sale_id', $result['sale']->id)->orderBy('id')->get();

        $this->assertSame(30.00, (float) $result['sale']->fresh()->paid_amount);
        $this->assertSame(10.00, (float) $payments[0]->montant);
        $this->assertSame(10.00, (float) $payments[1]->montant);
    }

    public function test_voucher_can_cover_entire_sale_without_regular_payment(): void
    {
        $result = $this->simulateVoucherSale(15, 30, [
            ['amount' => 0, 'payment_method_id' => 2],
        ]);

        $sale = $result['sale']->fresh();
        $voucher = $result['voucher']->fresh();

        $this->assertSame(15.00, (float) $sale->paid_amount);
        $this->assertSame('paid', $sale->payment_statut);
        $this->assertSame(15.00, (float) $voucher->remaining_balance);
        $this->assertSame(0, PaymentSale::where('sale_id', $sale->id)->count());
    }

    private function simulateVoucherSale(float $grandTotal, float $voucherBalance, array $payments): array
    {
        $user = User::create(['username' => 'cashier', 'email' => uniqid().'@example.test']);
        $warehouse = Warehouse::create(['name' => 'Principal']);
        $register = CashRegister::create([
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'opening_balance' => 100,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);
        $sale = Sale::create([
            'date' => Carbon::today()->toDateString(),
            'Ref' => uniqid('SL-'),
            'is_pos' => 1,
            'client_id' => 1,
            'GrandTotal' => $grandTotal,
            'warehouse_id' => $warehouse->id,
            'user_id' => $user->id,
            'statut' => 'completed',
            'payment_statut' => 'unpaid',
            'paid_amount' => 0,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);
        $voucherCode = strtoupper(uniqid('VAL-'));
        $voucher = StoreCreditVoucher::create([
            'code' => $voucherCode,
            'client_id' => 1,
            'warehouse_id' => $warehouse->id,
            'original_amount' => $voucherBalance,
            'remaining_balance' => $voucherBalance,
            'currency' => 'HNL',
            'status' => 'active',
            'issued_at' => Carbon::now()->subDay(),
        ]);

        $request = Request::create('/pos/create_pos', 'POST', [
            'client_id' => 1,
            'store_credit_vouchers' => [[
                'code' => $voucher->code,
                'amount' => min($voucherBalance, $grandTotal),
            ]],
        ]);

        $controller = new PosController;
        $storeCreditApplied = $this->invokePrivate($controller, 'redeemStoreCreditVouchers', [$request, $sale]);
        $remainingDue = max(0, $grandTotal - $storeCreditApplied);
        $normalizedPayments = $this->invokePrivate($controller, 'normalizePosPayments', [$payments, $remainingDue]);
        $regularApplied = collect($normalizedPayments)->sum('applied_amount');

        foreach ($normalizedPayments as $payment) {
            if ($payment['applied_amount'] <= 0) {
                continue;
            }

            PaymentSale::create([
                'sale_id' => $sale->id,
                'date' => Carbon::today()->toDateString(),
                'Ref' => uniqid('PAY-'),
                'payment_method_id' => $payment['payment_method_id'],
                'montant' => $payment['applied_amount'],
                'change' => $payment['change'],
                'user_id' => $user->id,
            ]);
        }

        $paidAmount = min($storeCreditApplied + $regularApplied, $grandTotal);
        $due = $grandTotal - $paidAmount;
        $sale->update([
            'store_credit_amount' => $storeCreditApplied,
            'paid_amount' => $paidAmount,
            'payment_statut' => $due <= 0 ? 'paid' : ($due < $grandTotal ? 'partial' : 'unpaid'),
        ]);

        return compact('sale', 'voucher', 'register');
    }

    private function invokePrivate(object $object, string $method, array $args)
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    private function createStoreCreditPaymentTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
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
            $table->timestamps();
            $table->softDeletes();
        });
        \DB::table('payment_methods')->insert([
            ['id' => 1, 'name' => 'Card', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Cash', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Schema::create('cash_registers', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('warehouse_id');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('cash_in', 15, 2)->default(0);
            $table->decimal('cash_out', 15, 2)->default(0);
            $table->string('status')->default('open');
            $table->timestamp('opened_at')->nullable();
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
            $table->decimal('store_credit_amount', 15, 2)->default(0);
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
        Schema::create('store_credit_vouchers', function ($table) {
            $table->increments('id');
            $table->string('code')->unique();
            $table->unsignedInteger('client_id')->nullable();
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->decimal('original_amount', 15, 2)->default(0);
            $table->decimal('remaining_balance', 15, 2)->default(0);
            $table->string('currency')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('store_credit_voucher_transactions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('voucher_id');
            $table->unsignedInteger('sale_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('warehouse_id')->nullable();
            $table->string('type');
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('balance_before', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
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

class StoreCreditTestableCashRegisterController extends CashRegisterController
{
    public function summary(CashRegister $register, Carbon $to): array
    {
        return $this->buildClosingSummary($register, $to);
    }
}
