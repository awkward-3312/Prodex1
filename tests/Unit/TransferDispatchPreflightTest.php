<?php

namespace Tests\Unit;

use App\Http\Middleware\LockTransferDispatchStock;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferDispatchPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public function test_duplicate_lines_are_aggregated_before_approval(): void
    {
        $unitId = $this->unit('*', 1);
        $productId = $this->product('Producto agregado', $unitId);
        $transferId = $this->transfer();
        $this->detail($transferId, $productId, $unitId, 12);
        $this->detail($transferId, $productId, $unitId, 12);
        $this->stock($productId, 20);

        $this->expectException(ValidationException::class);
        $this->runMiddleware($transferId);
    }

    public function test_purchase_unit_conversion_is_included_in_aggregate_preflight(): void
    {
        $boxId = $this->unit('*', 12);
        $productId = $this->product('Caja de 12', $boxId);
        $transferId = $this->transfer();
        $this->detail($transferId, $productId, $boxId, 1);
        $this->detail($transferId, $productId, $boxId, 1);
        $this->stock($productId, 23);

        $this->expectException(ValidationException::class);
        $this->runMiddleware($transferId);
    }

    public function test_missing_or_invalid_unit_definition_blocks_dispatch(): void
    {
        $productId = $this->product('Sin unidad', null);
        $transferId = $this->transfer();
        $this->detail($transferId, $productId, null, 2);
        $this->stock($productId, 100);

        $this->expectException(ValidationException::class);
        $this->runMiddleware($transferId);
    }

    public function test_valid_aggregate_stock_allows_request_to_continue(): void
    {
        $boxId = $this->unit('*', 12);
        $productId = $this->product('Caja válida', $boxId);
        $transferId = $this->transfer();
        $this->detail($transferId, $productId, $boxId, 1);
        $this->detail($transferId, $productId, $boxId, 1);
        $this->stock($productId, 24);

        $response = $this->runMiddleware($transferId);

        $this->assertSame('continued', $response);
        $this->assertEquals(24.0, (float) DB::table('product_warehouse')->value('qte'));
    }

    private function runMiddleware(int $transferId)
    {
        $request = Request::create('/api/transfers/'.$transferId.'/approve', 'POST');
        $route = new Route('POST', 'api/transfers/{id}/approve', [
            'uses' => 'App\\Http\\Controllers\\TransferController@approve',
        ]);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return app(LockTransferDispatchStock::class)->handle($request, fn () => 'continued');
    }

    private function unit(string $operator, float $operatorValue): int
    {
        return DB::table('units')->insertGetId([
            'ShortName' => 'u'.uniqid(),
            'operator' => $operator,
            'operator_value' => $operatorValue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(string $name, ?int $purchaseUnitId): int
    {
        return DB::table('products')->insertGetId([
            'name' => $name,
            'code' => 'P'.uniqid(),
            'unit_purchase_id' => $purchaseUnitId,
            'is_batch_tracked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transfer(): int
    {
        return DB::table('transfers')->insertGetId([
            'date' => now()->toDateString(),
            'time' => now()->format('H:i:s'),
            'Ref' => 'TR_PREFLIGHT_'.uniqid(),
            'user_id' => 1,
            'from_warehouse_id' => 1,
            'to_warehouse_id' => 2,
            'items' => 1,
            'statut' => 'sent',
            'approval_status' => 'pending',
            'GrandTotal' => 0,
            'discount' => 0,
            'shipping' => 0,
            'TaxNet' => 0,
            'tax_rate' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function detail(int $transferId, int $productId, ?int $unitId, float $quantity): void
    {
        DB::table('transfer_details')->insert([
            'transfer_id' => $transferId,
            'product_id' => $productId,
            'product_variant_id' => null,
            'purchase_unit_id' => $unitId,
            'quantity' => $quantity,
            'cost' => 0,
            'TaxNet' => 0,
            'discount' => 0,
            'discount_method' => '1',
            'tax_method' => '1',
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stock(int $productId, float $quantity): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId,
            'warehouse_id' => 1,
            'product_variant_id' => null,
            'qte' => $quantity,
            'manage_stock' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->integer('unit_purchase_id')->nullable();
            $table->boolean('is_batch_tracked')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function ($table) {
            $table->increments('id');
            $table->string('ShortName')->nullable();
            $table->string('operator')->nullable();
            $table->decimal('operator_value', 20, 6)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_warehouse', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('qte', 20, 6)->default(0);
            $table->integer('manage_stock')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transfers', function ($table) {
            $table->increments('id');
            $table->date('date')->nullable();
            $table->time('time')->nullable();
            $table->string('Ref')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('from_warehouse_id');
            $table->integer('to_warehouse_id');
            $table->decimal('items', 20, 6)->default(0);
            $table->string('statut')->default('sent');
            $table->string('approval_status')->nullable();
            $table->decimal('GrandTotal', 20, 6)->default(0);
            $table->decimal('discount', 20, 6)->default(0);
            $table->decimal('shipping', 20, 6)->default(0);
            $table->decimal('TaxNet', 20, 6)->default(0);
            $table->decimal('tax_rate', 20, 6)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transfer_details', function ($table) {
            $table->increments('id');
            $table->integer('transfer_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('purchase_unit_id')->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('cost', 20, 6)->default(0);
            $table->decimal('TaxNet', 20, 6)->default(0);
            $table->decimal('discount', 20, 6)->default(0);
            $table->string('discount_method')->nullable();
            $table->string('tax_method')->nullable();
            $table->decimal('total', 20, 6)->default(0);
            $table->timestamps();
        });
    }
}
