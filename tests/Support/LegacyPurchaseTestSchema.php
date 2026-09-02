<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * PRE-LOCATION-NATIVE BASELINE — MS0.
 *
 * Minimal tenant schema + helpers used by the Purchases / Purchase-returns
 * "golden master" characterization tests. It builds ONLY the tables the legacy
 * `PurchasesController` / `PurchasesReturnController` touch on
 * store / update / destroy / import when batch + serial tracking are OFF.
 *
 * Batch (`product_batches`, `purchase_detail_batches`) and serial
 * (`product_serials`, `product_serial_movements`) tables are intentionally NOT
 * created: `BatchService::isSupported()` / `SerialNumberService::isSupported()`
 * then return false and those code paths stay inert, so the golden master
 * isolates the `product_warehouse.qte` arithmetic that MS1/MS2 will change.
 * Batch/serial location behaviour gets its own coverage in MS5/MS6.
 */
trait LegacyPurchaseTestSchema
{
    protected function buildLegacyPurchaseSchema(): void
    {
        // settings row already exists (Tests\TestCase base), but without the
        // columns getNumberOrder() and the accounting guard read.
        if (! Schema::hasColumn('settings', 'deleted_at')) {
            Schema::table('settings', function ($t) {
                $t->timestamp('deleted_at')->nullable();
            });
        }
        if (! Schema::hasColumn('settings', 'purchase_prefix')) {
            Schema::table('settings', function ($t) {
                $t->string('purchase_prefix', 10)->nullable()->default('PR');
                $t->string('purchase_return_prefix', 10)->nullable()->default('RP');
            });
        }
        DB::table('settings')->insert(['id' => 1]);

        Schema::create('users', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->integer('role_id')->nullable();
            $t->boolean('is_all_warehouses')->default(1);
            $t->boolean('record_view')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('default_inventory_location_id')->nullable();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->string('type')->default('is_single');
            $t->integer('unit_id')->nullable();
            $t->integer('unit_purchase_id')->nullable();
            $t->integer('unit_sale_id')->nullable();
            $t->boolean('is_batch_tracked')->default(false);
            $t->integer('is_imei')->default(0);
            $t->decimal('cost', 15, 4)->default(0);
            $t->decimal('price', 15, 4)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('product_variants', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('units', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('ShortName')->nullable();
            $t->integer('base_unit')->nullable();
            $t->string('operator')->nullable();
            $t->float('operator_value')->default(1);
            $t->integer('is_active')->default(1);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('product_warehouse', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('warehouse_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('qte', 12, 3)->default(0);
            $t->boolean('manage_stock')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('purchases', function ($t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->string('Ref')->nullable();
            $t->date('date')->nullable();
            $t->time('time')->nullable();
            $t->integer('provider_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->decimal('tax_rate', 15, 3)->nullable()->default(0);
            $t->decimal('TaxNet', 15, 3)->nullable()->default(0);
            $t->decimal('discount', 15, 3)->nullable()->default(0);
            $t->decimal('shipping', 15, 3)->nullable()->default(0);
            $t->decimal('GrandTotal', 15, 3)->default(0);
            $t->decimal('paid_amount', 15, 3)->default(0);
            $t->string('statut')->nullable();
            $t->string('payment_statut')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('purchase_details', function ($t) {
            $t->increments('id');
            $t->decimal('cost', 15, 4)->default(0);
            $t->integer('purchase_unit_id')->nullable();
            $t->decimal('TaxNet', 15, 3)->nullable()->default(0);
            $t->string('tax_method')->nullable()->default('1');
            $t->decimal('discount', 15, 3)->nullable()->default(0);
            $t->string('discount_method')->nullable()->default('1');
            $t->integer('purchase_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->text('imei_number')->nullable();
            $t->decimal('total', 15, 3)->default(0);
            $t->decimal('quantity', 12, 3)->default(0);
            $t->timestamps();
        });

        Schema::create('purchase_returns', function ($t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->date('date')->nullable();
            $t->time('time')->nullable();
            $t->string('Ref')->nullable();
            $t->integer('purchase_id')->nullable();
            $t->integer('provider_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->decimal('tax_rate', 15, 3)->nullable()->default(0);
            $t->decimal('TaxNet', 15, 3)->nullable()->default(0);
            $t->decimal('discount', 15, 3)->nullable()->default(0);
            $t->decimal('shipping', 15, 3)->nullable()->default(0);
            $t->decimal('GrandTotal', 15, 3)->default(0);
            $t->decimal('paid_amount', 15, 3)->default(0);
            $t->string('payment_statut')->nullable();
            $t->string('statut')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('purchase_return_details', function ($t) {
            $t->increments('id');
            $t->decimal('cost', 16, 3)->default(0);
            $t->integer('purchase_unit_id')->nullable();
            $t->decimal('TaxNet', 15, 3)->nullable()->default(0);
            $t->string('tax_method')->nullable()->default('1');
            $t->decimal('discount', 15, 3)->nullable()->default(0);
            $t->string('discount_method')->nullable()->default('1');
            $t->decimal('total', 15, 3)->default(0);
            $t->decimal('quantity', 12, 3)->default(0);
            $t->integer('purchase_return_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->text('imei_number')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('purchase_extra_charges', function ($t) {
            $t->increments('id');
            $t->integer('purchase_id');
            $t->string('name')->nullable();
            $t->decimal('amount', 15, 3)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('purchase_custom_fields', function ($t) {
            $t->increments('id');
            $t->integer('purchase_id');
            $t->string('name')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('payment_purchases', function ($t) {
            $t->increments('id');
            $t->integer('purchase_id')->nullable();
            $t->integer('account_id')->nullable();
            $t->decimal('montant', 15, 3)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('payment_purchase_returns', function ($t) {
            $t->increments('id');
            $t->integer('purchase_return_id')->nullable();
            $t->integer('account_id')->nullable();
            $t->decimal('montant', 15, 3)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /** @var User */
    protected $legacyOwnerUser;

    protected function legacyOwner(): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'role_id' => 1,          // tenant owner => hasRecordView() === true
            'is_all_warehouses' => 1, // skips the UserWarehouse restriction branch
        ])->save();

        $this->legacyOwnerUser = $user;
        $this->actingAs($user, 'api');
        $this->actingAs($user);

        // The golden master exercises the controllers' business logic, not the
        // PurchasePolicy (which needs a full permissions/roles fixture). Allow
        // every gate check (the nullable param keeps it valid for guest checks).
        Gate::before(fn ($u = null) => true);

        return $user;
    }

    protected function makeWarehouse(string $name = 'Central'): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  string  $operator  '*' or '/'
     */
    protected function makeUnit(string $operator = '*', float $operatorValue = 1.0): int
    {
        return (int) DB::table('units')->insertGetId([
            'name' => $operator.$operatorValue,
            'ShortName' => 'U',
            'operator' => $operator,
            'operator_value' => $operatorValue,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeProduct(array $overrides = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'name' => 'Prod',
            'code' => 'P'.\Illuminate\Support\Str::random(6),
            'type' => 'is_single',
            'is_batch_tracked' => false,
            'is_imei' => 0,
            'cost' => 0,
            'price' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeVariant(int $productId, string $name = 'V1'): int
    {
        return (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'name' => $name,
            'code' => 'V'.\Illuminate\Support\Str::random(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Seed a product_warehouse row (the legacy controllers never create it). */
    protected function seedStock(int $warehouseId, int $productId, float $qte, ?int $variantId = null): int
    {
        return (int) DB::table('product_warehouse')->insertGetId([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'qte' => $qte,
            'manage_stock' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function stockOf(int $warehouseId, int $productId, ?int $variantId = null): float
    {
        $q = DB::table('product_warehouse')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at');
        $variantId === null ? $q->whereNull('product_variant_id') : $q->where('product_variant_id', $variantId);

        return round((float) $q->value('qte'), 3);
    }

    // ---------------------------------------------------------------------
    // Controller invocation (bypasses HTTP kernel / route model binding).
    // ---------------------------------------------------------------------

    /**
     * Build a Request, bind it as the container `request` (so the controller's
     * `request()->validate(...)` sees it) and resolve the acting user through it.
     */
    protected function makeRequest(array $payload, string $method = 'POST', array $files = []): Request
    {
        $request = Request::create('/', $method, $payload, [], $files, [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $user = $this->legacyOwnerUser ?? Auth::user();
        $request->setUserResolver(fn () => $user);
        app()->instance('request', $request);

        return $request;
    }
}
