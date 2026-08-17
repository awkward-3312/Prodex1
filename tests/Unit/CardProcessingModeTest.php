<?php

namespace Tests\Unit;

use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\PosController;
use App\Models\CashRegister;
use App\Models\PaymentSale;
use App\Models\PaymentSetting;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class CardProcessingModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_external_terminal_mode_does_not_require_stripe_credentials(): void
    {
        PaymentSetting::create([
            'card_processing_mode' => PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL,
        ]);

        $controller = new CardProcessingTestablePosController;

        $this->assertSame(PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL, $controller->cardMode());
        $this->assertTrue($controller->isCardMethod(1));
        $this->assertFalse(PaymentSetting::current()->hasStripeCredentials());
    }

    public function test_stripe_mode_is_explicit_and_requires_credentials_for_card_flow(): void
    {
        PaymentSetting::create([
            'card_processing_mode' => PaymentSetting::CARD_MODE_STRIPE,
            'stripe_key' => null,
            'stripe_secret' => null,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stripe card payment is not configured for this tenant.');

        $this->assertSame(PaymentSetting::CARD_MODE_STRIPE, PaymentSetting::current()->effectiveCardProcessingMode());
        $this->assertFalse(PaymentSetting::current()->hasStripeCredentials());
        (new CardProcessingTestablePosController)->stripeSettings();
    }

    public function test_external_terminal_card_payment_stores_audit_fields_and_counts_as_card_total(): void
    {
        PaymentSetting::create(['card_processing_mode' => PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL]);
        $data = $this->createOpenRegisterSale(30);

        PaymentSale::create([
            'sale_id' => $data['sale']->id,
            'date' => Carbon::today()->toDateString(),
            'Ref' => 'PAY-EXT-1',
            'payment_method_id' => 1,
            'montant' => 30,
            'change' => 0,
            'user_id' => $data['user']->id,
            'card_processor' => PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL,
            'card_reference' => 'TERM-88991',
            'authorization_code' => 'AUTH123',
        ]);

        $payment = PaymentSale::first();
        $summary = (new CardProcessingTestableCashRegisterController)->summary($data['register']->fresh(), Carbon::now());

        $this->assertSame(PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL, $payment->card_processor);
        $this->assertSame('TERM-88991', $payment->card_reference);
        $this->assertSame('AUTH123', $payment->authorization_code);
        $this->assertSame(30.00, $summary['card_system_total']);
    }

    public function test_store_credit_plus_external_terminal_card_covers_only_remaining_due(): void
    {
        PaymentSetting::create(['card_processing_mode' => PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL]);
        $data = $this->createOpenRegisterSale(30);
        $controller = new PosController;
        $normalized = $this->invokePrivate($controller, 'normalizePosPayments', [[
            ['amount' => 11, 'payment_method_id' => 1, 'card_reference' => 'TERM-19'],
        ], 11]);

        PaymentSale::create([
            'sale_id' => $data['sale']->id,
            'date' => Carbon::today()->toDateString(),
            'Ref' => 'PAY-EXT-2',
            'payment_method_id' => 1,
            'montant' => $normalized[0]['applied_amount'],
            'change' => $normalized[0]['change'],
            'user_id' => $data['user']->id,
            'card_processor' => PaymentSetting::CARD_MODE_EXTERNAL_TERMINAL,
            'card_reference' => $normalized[0]['card_reference'],
        ]);

        $summary = (new CardProcessingTestableCashRegisterController)->summary($data['register']->fresh(), Carbon::now());

        $this->assertSame(11.00, (float) PaymentSale::first()->montant);
        $this->assertSame(0.00, (float) PaymentSale::first()->change);
        $this->assertSame(11.00, $summary['card_system_total']);
    }

    private function createOpenRegisterSale(float $grandTotal): array
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
            'payment_statut' => 'paid',
            'paid_amount' => $grandTotal,
            'created_at' => Carbon::now()->subMinutes(10),
        ]);

        return compact('user', 'warehouse', 'register', 'sale');
    }

    private function invokePrivate(object $object, string $method, array $args)
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    private function createTables(): void
    {
        Schema::create('payment_settings', function ($table) {
            $table->increments('id');
            $table->string('stripe_key')->nullable();
            $table->text('stripe_secret')->nullable();
            $table->string('card_processing_mode', 50)->default('external_terminal');
            $table->timestamps();
        });
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
            ['id' => 1, 'name' => 'Tarjeta', 'created_at' => now(), 'updated_at' => now()],
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
            $table->unsignedInteger('account_id')->nullable();
            $table->string('card_processor', 50)->nullable();
            $table->string('card_reference')->nullable();
            $table->string('authorization_code')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->decimal('montant', 15, 2)->default(0);
            $table->decimal('change', 15, 2)->default(0);
            $table->unsignedInteger('payment_method_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->text('notes')->nullable();
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

class CardProcessingTestablePosController extends PosController
{
    public function cardMode(): string
    {
        return $this->resolveCardProcessingMode();
    }

    public function isCardMethod($paymentMethodId): bool
    {
        return $this->isCardPaymentMethod($paymentMethodId);
    }

    public function stripeSettings(): PaymentSetting
    {
        return $this->stripePaymentSettings();
    }
}

class CardProcessingTestableCashRegisterController extends CashRegisterController
{
    public function summary(CashRegister $register, Carbon $to): array
    {
        return $this->buildClosingSummary($register, $to);
    }
}
