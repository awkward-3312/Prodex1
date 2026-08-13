<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo Tenant Generator.
 *
 * Populates a freshly provisioned tenant with realistic sample data so the
 * dashboard, catalog and reports look alive out of the box: categories,
 * brands, units, products with stock, customers, suppliers, plus example
 * purchases and sales (each with payments).
 *
 * Run only when the super admin enables the "Demo Tenant Generator" toggle in
 * Super → General Settings. It uses raw inserts (no Eloquent events) and is
 * idempotent — it bails out if demo products already exist.
 *
 * Inventory stays consistent: each product's on-hand stock equals the total
 * purchased minus the total sold across the generated transactions.
 */
class DemoDataSeeder extends Seeder
{
    /** Default warehouse + admin user are created by DatabaseSeeder. */
    private const WAREHOUSE_ID = 1;
    private const USER_ID = 1;

    public function run(): void
    {
        // Idempotency guard — never duplicate demo data on re-provision.
        if (DB::table('products')->where('code', 'PR-DEMO-001')->exists()) {
            return;
        }

        DB::transaction(function () {
            $now = Carbon::now();

            $units      = $this->seedUnits();
            $categories = $this->seedCategories();
            $brands     = $this->seedBrands();
            $products   = $this->seedProducts($units, $categories, $brands, $now);
            $providers  = $this->seedProviders($now);
            $clients    = $this->seedClients($now);

            // Track on-hand stock per product as transactions are recorded.
            $onHand = array_fill_keys(array_keys($products), 0.0);

            $this->seedPurchases($products, $providers, $onHand, $now);
            $this->seedSales($products, $clients, $onHand, $now);

            $this->seedStock($products, $onHand, $now);
        });
    }

    /** @return array<string,int> short-name => id */
    private function seedUnits(): array
    {
        $now = Carbon::now();
        $rows = [
            'pc'  => ['name' => 'Piece',     'ShortName' => 'pc'],
            'pk'  => ['name' => 'Pack',      'ShortName' => 'pk'],
            'box' => ['name' => 'Box',       'ShortName' => 'box'],
        ];

        $ids = [];
        foreach ($rows as $key => $row) {
            $ids[$key] = DB::table('units')->insertGetId($row + [
                'operator'       => '*',
                'operator_value' => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }

        return $ids;
    }

    /** @return array<string,int> key => id */
    private function seedCategories(): array
    {
        $now = Carbon::now();
        $rows = [
            'beverages'   => ['code' => 'CAT-DEMO-BEV', 'name' => 'Beverages'],
            'snacks'      => ['code' => 'CAT-DEMO-SNK', 'name' => 'Snacks'],
            'electronics' => ['code' => 'CAT-DEMO-ELC', 'name' => 'Electronics'],
            'stationery'  => ['code' => 'CAT-DEMO-STN', 'name' => 'Stationery'],
        ];

        $ids = [];
        foreach ($rows as $key => $row) {
            $ids[$key] = DB::table('categories')->insertGetId($row + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /** @return array<string,int> key => id */
    private function seedBrands(): array
    {
        $now = Carbon::now();
        $names = [
            'aquapure' => 'AquaPure',
            'crunchco' => 'CrunchCo',
            'technova' => 'TechNova',
            'writewell' => 'WriteWell',
        ];

        $ids = [];
        foreach ($names as $key => $name) {
            $ids[$key] = DB::table('brands')->insertGetId([
                'name'       => $name,
                'image'      => 'no-image.png',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * @return array<string,array{id:int,cost:float,price:float}> sku => meta
     */
    private function seedProducts(array $units, array $categories, array $brands, Carbon $now): array
    {
        // sku, name, category, brand, unit, cost, price
        $defs = [
            ['PR-DEMO-001', 'Mineral Water 500ml',  'beverages',   'aquapure',  'pc', 0.30, 0.80],
            ['PR-DEMO-002', 'Cola Can 330ml',        'beverages',   'aquapure',  'pc', 0.50, 1.20],
            ['PR-DEMO-003', 'Potato Chips 100g',     'snacks',      'crunchco',  'pc', 0.70, 1.80],
            ['PR-DEMO-004', 'Chocolate Bar 50g',     'snacks',      'crunchco',  'pc', 0.60, 1.50],
            ['PR-DEMO-005', 'USB-C Cable 1m',        'electronics', 'technova',  'pc', 2.00, 5.00],
            ['PR-DEMO-006', 'Wireless Mouse',        'electronics', 'technova',  'pc', 6.00, 14.00],
            ['PR-DEMO-007', 'Notebook A5',           'stationery',  'writewell', 'pc', 0.90, 2.50],
            ['PR-DEMO-008', 'Ballpoint Pen',         'stationery',  'writewell', 'pc', 0.20, 0.60],
        ];

        $products = [];
        foreach ($defs as [$sku, $name, $cat, $brand, $unit, $cost, $price]) {
            $id = DB::table('products')->insertGetId([
                'type'             => 'is_single',
                'code'             => $sku,
                'Type_barcode'     => 'CODE128',
                'name'             => $name,
                'image'            => 'no-image.png',
                'cost'             => $cost,
                'price'            => $price,
                'wholesale_price'  => round($price * 0.9, 2),
                'min_price'        => round($price * 0.8, 2),
                'points'           => 0,
                'category_id'      => $categories[$cat],
                'brand_id'         => $brands[$brand],
                'unit_id'          => $units[$unit],
                'unit_sale_id'     => $units[$unit],
                'unit_purchase_id' => $units[$unit],
                'TaxNet'           => 0,
                'tax_method'       => '1',
                'stock_alert'      => 10,
                'is_active'        => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $products[$sku] = ['id' => $id, 'cost' => $cost, 'price' => $price];
        }

        return $products;
    }

    /** @return array<int,int> list of provider ids */
    private function seedProviders(Carbon $now): array
    {
        $defs = [
            ['Global Supplies Co',     'sales@globalsupplies.test',  '+1-202-555-0111'],
            ['FreshMart Distributors', 'orders@freshmart.test',      '+1-202-555-0122'],
            ['TechWorld Wholesale',    'hello@techworld.test',       '+1-202-555-0133'],
        ];

        $ids = [];
        foreach ($defs as $i => [$name, $email, $phone]) {
            $ids[] = DB::table('providers')->insertGetId([
                'name'       => $name,
                'code'       => $i + 1,
                'email'      => $email,
                'phone'      => $phone,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /** @return array<int,int> list of client ids */
    private function seedClients(Carbon $now): array
    {
        $defs = [
            ['Walk-in Customer',  null,                       null],
            ['John Carter',       'john.carter@example.test',  '+1-202-555-0200'],
            ['Emily Stone',       'emily.stone@example.test',  '+1-202-555-0201'],
            ['Michael Reed',      'michael.reed@example.test', '+1-202-555-0202'],
            ['Sophia Bennett',    'sophia.bennett@example.test','+1-202-555-0203'],
        ];

        $ids = [];
        foreach ($defs as $i => [$name, $email, $phone]) {
            $ids[] = DB::table('clients')->insertGetId([
                'name'                => $name,
                'code'                => $i + 1,
                'email'               => $email,
                'phone'               => $phone,
                'is_royalty_eligible' => true,
                'points'              => 0,
                'opening_balance'     => 0,
                'credit_limit'        => 0,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }

        return $ids;
    }

    /**
     * Record received purchases (which build up stock) with payments.
     *
     * @param array<string,array{id:int,cost:float,price:float}> $products
     * @param array<int,int> $providers
     * @param array<int,float> $onHand  by ref — incremented per purchased unit
     */
    private function seedPurchases(array $products, array $providers, array &$onHand, Carbon $now): void
    {
        // providerIndex, daysAgo, paidFraction, items => [sku => qty]
        $defs = [
            [0, 25, 1.0, ['PR-DEMO-001' => 100, 'PR-DEMO-002' => 80]],
            [1, 20, 0.5, ['PR-DEMO-003' => 120, 'PR-DEMO-004' => 90, 'PR-DEMO-007' => 150, 'PR-DEMO-008' => 300]],
            [2, 14, 1.0, ['PR-DEMO-005' => 40, 'PR-DEMO-006' => 25]],
        ];

        foreach ($defs as $i => [$providerIdx, $daysAgo, $paidFraction, $items]) {
            $date = $now->copy()->subDays($daysAgo);

            $grandTotal = 0.0;
            foreach ($items as $sku => $qty) {
                $grandTotal += $qty * $products[$sku]['cost'];
            }
            $grandTotal = round($grandTotal, 2);
            $paid       = round($grandTotal * $paidFraction, 2);

            $ref = sprintf('PUR-DEMO-%04d', $i + 1);

            $purchaseId = DB::table('purchases')->insertGetId([
                'user_id'        => self::USER_ID,
                'Ref'            => $ref,
                'date'           => $date->toDateString(),
                'time'           => $date->toTimeString(),
                'provider_id'    => $providers[$providerIdx],
                'warehouse_id'   => self::WAREHOUSE_ID,
                'tax_rate'       => 0,
                'TaxNet'         => 0,
                'discount'       => 0,
                'shipping'       => 0,
                'GrandTotal'     => $grandTotal,
                'paid_amount'    => $paid,
                'statut'         => 'received',
                'payment_statut' => $this->paymentStatut($grandTotal, $paid),
                'created_at'     => $date,
                'updated_at'     => $date,
            ]);

            foreach ($items as $sku => $qty) {
                $cost = $products[$sku]['cost'];
                DB::table('purchase_details')->insert([
                    'cost'             => $cost,
                    'purchase_unit_id' => null,
                    'TaxNet'           => 0,
                    'tax_method'       => '1',
                    'discount'         => 0,
                    'discount_method'  => '1',
                    'purchase_id'      => $purchaseId,
                    'product_id'       => $products[$sku]['id'],
                    'total'            => round($qty * $cost, 2),
                    'quantity'         => $qty,
                    'created_at'       => $date,
                    'updated_at'       => $date,
                ]);

                $onHand[$sku] += $qty;
            }

            if ($paid > 0) {
                DB::table('payment_purchases')->insert([
                    'user_id'     => self::USER_ID,
                    'date'        => $date->toDateString(),
                    'Ref'         => 'INV/' . $ref,
                    'purchase_id' => $purchaseId,
                    'montant'     => $paid,
                    'change'      => 0,
                    'created_at'  => $date,
                    'updated_at'  => $date,
                ]);
            }
        }
    }

    /**
     * Record completed sales (which draw down stock) with payments.
     *
     * @param array<string,array{id:int,cost:float,price:float}> $products
     * @param array<int,int> $clients
     * @param array<string,float> $onHand  by ref — decremented per sold unit
     */
    private function seedSales(array $products, array $clients, array &$onHand, Carbon $now): void
    {
        // clientIndex, daysAgo, paidFraction, items => [sku => qty]
        $defs = [
            [1, 12, 1.0, ['PR-DEMO-001' => 10, 'PR-DEMO-003' => 5]],
            [2, 10, 1.0, ['PR-DEMO-006' => 2, 'PR-DEMO-005' => 3]],
            [3,  8, 0.5, ['PR-DEMO-002' => 12, 'PR-DEMO-004' => 8, 'PR-DEMO-008' => 20]],
            [4,  5, 1.0, ['PR-DEMO-007' => 15, 'PR-DEMO-008' => 25]],
            [1,  3, 1.0, ['PR-DEMO-001' => 8, 'PR-DEMO-002' => 6, 'PR-DEMO-003' => 4]],
            [0,  1, 0.0, ['PR-DEMO-004' => 5, 'PR-DEMO-006' => 1]],
        ];

        foreach ($defs as $i => [$clientIdx, $daysAgo, $paidFraction, $items]) {
            $date = $now->copy()->subDays($daysAgo);

            $grandTotal = 0.0;
            foreach ($items as $sku => $qty) {
                $grandTotal += $qty * $products[$sku]['price'];
            }
            $grandTotal = round($grandTotal, 2);
            $paid       = round($grandTotal * $paidFraction, 2);

            $ref = sprintf('SL-DEMO-%04d', $i + 1);

            $saleId = DB::table('sales')->insertGetId([
                'user_id'        => self::USER_ID,
                'date'           => $date->toDateString(),
                'time'           => $date->toTimeString(),
                'Ref'            => $ref,
                'is_pos'         => false,
                'client_id'      => $clients[$clientIdx],
                'warehouse_id'   => self::WAREHOUSE_ID,
                'tax_rate'       => 0,
                'TaxNet'         => 0,
                'discount'       => 0,
                'discount_Method' => '2',
                'shipping'       => 0,
                'GrandTotal'     => $grandTotal,
                'paid_amount'    => $paid,
                'payment_statut' => $this->paymentStatut($grandTotal, $paid),
                'statut'         => 'completed',
                'created_at'     => $date,
                'updated_at'     => $date,
            ]);

            foreach ($items as $sku => $qty) {
                $price = $products[$sku]['price'];
                DB::table('sale_details')->insert([
                    'date'         => $date->toDateString(),
                    'sale_id'      => $saleId,
                    'product_id'   => $products[$sku]['id'],
                    'price'        => $price,
                    'sale_unit_id' => null,
                    'TaxNet'       => 0,
                    'tax_method'   => '1',
                    'discount'     => 0,
                    'discount_method' => '1',
                    'price_type'   => 'retail',
                    'total'        => round($qty * $price, 2),
                    'quantity'     => $qty,
                    'created_at'   => $date,
                    'updated_at'   => $date,
                ]);

                $onHand[$sku] -= $qty;
            }

            if ($paid > 0) {
                DB::table('payment_sales')->insert([
                    'user_id'    => self::USER_ID,
                    'date'       => $date->toDateString(),
                    'Ref'        => 'INV/' . $ref,
                    'sale_id'    => $saleId,
                    'montant'    => $paid,
                    'change'     => 0,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }

    /**
     * Write the resulting on-hand stock for each product into the default
     * warehouse.
     *
     * @param array<string,array{id:int,cost:float,price:float}> $products
     * @param array<string,float> $onHand
     */
    private function seedStock(array $products, array $onHand, Carbon $now): void
    {
        foreach ($products as $sku => $meta) {
            DB::table('product_warehouse')->insert([
                'product_id'   => $meta['id'],
                'warehouse_id' => self::WAREHOUSE_ID,
                'qte'          => max(0, $onHand[$sku]),
                'manage_stock' => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    private function paymentStatut(float $grandTotal, float $paid): string
    {
        if ($paid <= 0) {
            return 'unpaid';
        }

        return $paid >= $grandTotal ? 'paid' : 'partial';
    }
}
