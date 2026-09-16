<?php

namespace App\Services\DemoTenant;

use App\Http\Controllers\PosController;
use App\Http\Controllers\PromotionsController;
use App\Http\Controllers\PurchasesController;
use App\Http\Controllers\QuotationsController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransferWorkflowController;
use App\Models\User;
use App\Services\UserOperationalAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Builds/backfills a realistic demo dataset inside the CURRENTLY INITIALIZED
 * tenant connection. Caller (the command) is responsible for
 * tenancy()->initialize()/end() — this class never touches tenant
 * resolution or the central DB.
 *
 * Idempotency: granular, per entity, not one global marker.
 *  - categories, products: looked up by their deterministic `code` column.
 *  - brands, units: looked up by exact `name` (unit also by `ShortName`)
 *    against the fixed persona list — see DemoPersonas for why that's safe.
 * A module method NEVER assumes a previous module fully succeeded; each
 * re-derives what already exists and only creates what's missing, so a
 * second run after a partial failure completes the gap without duplicating
 * anything already there.
 *
 * Every module returns a ModuleResult (created/existing/skipped/failed
 * counts) so the command can render the final summary table. Dry-run mode
 * NEVER calls a write method — plan() computes the same "what would be
 * created" answer via read-only counting, no INSERT/UPDATE/DELETE, no
 * files, no domain-service side effects.
 */
class DemoTenantSeeder
{
    private string $persona;

    private array $data;

    /** @var array<string,int> code => id, filled as modules run so later modules can reference earlier ones */
    private array $categoryIds = [];

    private array $brandIds = [];

    private array $unitIds = [];

    private array $clientIds = [];

    public function __construct(string $persona)
    {
        $this->persona = $persona;
        $this->data = DemoPersonas::get($persona);
    }

    public function personaLabel(): string
    {
        return $this->data['label'];
    }

    /**
     * Read-only plan for --dry-run: for each Phase-A entity, how many exist
     * already (matching this persona's fixed identifiers) vs. how many this
     * run would still need to create. Zero writes.
     */
    public function plan(): array
    {
        $plan = [];

        $unitExisting = 0;
        foreach ($this->data['units'] as $u) {
            if (DB::table('units')->where('name', $u['name'])->where('ShortName', $u['short'])->whereNull('deleted_at')->exists()) {
                $unitExisting++;
            }
        }
        $plan['Unidades'] = $this->row(count($this->data['units']), $unitExisting);

        $brandExisting = 0;
        foreach ($this->data['brands'] as $name) {
            if (DB::table('brands')->where('name', $name)->whereNull('deleted_at')->exists()) {
                $brandExisting++;
            }
        }
        $plan['Marcas'] = $this->row(count($this->data['brands']), $brandExisting);

        $categoryCodes = $this->categoryCodes();
        $catExisting = DB::table('categories')->whereIn('code', $categoryCodes)->whereNull('deleted_at')->count();
        $plan['Categorías'] = $this->row(count($categoryCodes), $catExisting);

        $productCodes = $this->productCodes();
        $prodExisting = DB::table('products')->whereIn('code', $productCodes)->whereNull('deleted_at')->count();
        $plan['Productos'] = $this->row(count($productCodes), $prodExisting);

        $provExisting = 0;
        foreach ($this->data['providers'] as $name) {
            if (DB::table('providers')->where('name', $name)->whereNull('deleted_at')->exists()) {
                $provExisting++;
            }
        }
        $plan['Proveedores'] = $this->row(count($this->data['providers']), $provExisting);

        $productIds = $this->allDemoProductIds();
        $warehouseIds = $this->allWarehouseIds();
        $targetStockRows = count($productIds) * count($warehouseIds);
        $existingStockRows = count($productIds) && count($warehouseIds)
            ? DB::table('product_warehouse')->whereIn('product_id', $productIds)->whereIn('warehouse_id', $warehouseIds)->whereNull('deleted_at')->count()
            : 0;
        $plan['Stock inicial (product_warehouse)'] = $this->row($targetStockRows, $existingStockRows);

        $purchaseExisting = DB::table('purchases')->where('notes', 'like', '%[DEMO2-PUR-%')->whereNull('deleted_at')->count();
        $plan['Compras'] = $this->row(10, $purchaseExisting);

        $transferExisting = DB::table('transfers')->where('notes', 'like', '%[DEMO2-TRF-%')->whereNull('deleted_at')->count();
        $plan['Transferencias'] = $this->row(10, $transferExisting);

        $clientExisting = DB::table('clients')->whereIn('name', $this->data['clients'])->whereNull('deleted_at')->count();
        $plan['Clientes'] = $this->row(count($this->data['clients']), $clientExisting);

        $saleExisting = DB::table('sales')->where('sale_uuid', 'like', self::SALE_UUID_PREFIX.'%')->whereNull('deleted_at')->count();
        $plan['Ventas'] = $this->row(10, $saleExisting);

        $quotationExisting = DB::table('quotations')->where('notes', 'like', '%[DEMO2-QUO-%')->whereNull('deleted_at')->count();
        $plan['Cotizaciones'] = $this->row(10, $quotationExisting);

        $promotionExisting = DB::table('promotions')->where('code', 'like', 'DEMO2-PROMO-%')->whereNull('deleted_at')->count();
        $plan['Promociones'] = $this->row(10, $promotionExisting);

        // Reservas: NO-GO — see seedReservasDecision() docblock. Reported as
        // target=10/existing=0/to_create=0 (never attempted) so the dry-run
        // table still surfaces the module instead of silently omitting it.
        $plan['Reservas'] = ['target' => 10, 'existing' => 0, 'to_create' => 0];

        return $plan;
    }

    /** Deterministic 36-char sale_uuid prefix, matched against the sales.sale_uuid unique column. */
    private const SALE_UUID_PREFIX = 'demo2-sale-';

    /** All demo product ids currently in the DB (Phase A codes), re-resolved fresh — never assumes Phase A ran in this same process. */
    private function allDemoProductIds(): array
    {
        return DB::table('products')->whereIn('code', $this->productCodes())->whereNull('deleted_at')->pluck('id')->all();
    }

    private function allWarehouseIds(): array
    {
        return DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->all();
    }

    /**
     * Units/brands (and providers, Phase B) have no `code`/`slug` column in
     * this schema — see class docblock. Before writing anything, surface
     * which of the fixed DEMO names ALREADY exist in this tenant: those get
     * reused (never duplicated, never modified), but a name match can never
     * be technically distinguished from an unrelated normal record sharing
     * the same name. This is a visibility report only — it never blocks
     * execution, per the explicit design decision.
     */
    public function nameCollisionReport(): array
    {
        $report = [];

        $report['Units'] = array_map(function ($u) {
            $row = DB::table('units')->where('name', $u['name'])->where('ShortName', $u['short'])->whereNull('deleted_at')->first();

            return ['label' => $u['name'].' ('.$u['short'].')', 'existing_id' => $row->id ?? null];
        }, $this->data['units']);

        $report['Brands'] = array_map(function ($name) {
            $id = DB::table('brands')->where('name', $name)->whereNull('deleted_at')->value('id');

            return ['label' => $name, 'existing_id' => $id];
        }, $this->data['brands']);

        $report['Providers'] = array_map(function ($name) {
            $id = DB::table('providers')->where('name', $name)->whereNull('deleted_at')->value('id');

            return ['label' => $name, 'existing_id' => $id];
        }, $this->data['providers']);

        $report['Clients'] = array_map(function ($name) {
            $id = DB::table('clients')->where('name', $name)->whereNull('deleted_at')->value('id');

            return ['label' => $name, 'existing_id' => $id];
        }, $this->data['clients']);

        return $report;
    }

    private function row(int $target, int $existing): array
    {
        return [
            'target' => $target,
            'existing' => $existing,
            'to_create' => max(0, $target - $existing),
        ];
    }

    /** Deterministic category codes, stable across runs: CAT-DEMO2-01..10 */
    private function categoryCodes(): array
    {
        $codes = [];
        $i = 1;
        foreach (array_keys($this->data['categories']) as $name) {
            $codes[] = sprintf('CAT-DEMO2-%02d', $i++);
        }

        return $codes;
    }

    /** Deterministic product codes, stable across runs: PR-DEMO2-001..100 */
    private function productCodes(): array
    {
        $codes = [];
        $n = 1;
        foreach ($this->data['categories'] as $items) {
            foreach ($items as $ignored) {
                $codes[] = sprintf('PR-DEMO2-%03d', $n++);
            }
        }

        return $codes;
    }

    // ------------------------------------------------------------------
    // Phase A — catalog + products. Each returns ['created'=>n,'existing'=>n,'skipped'=>n,'failed'=>n].
    // ------------------------------------------------------------------

    public function seedUnits(): array
    {
        $created = 0;
        $existing = 0;
        foreach ($this->data['units'] as $u) {
            $row = DB::table('units')->where('name', $u['name'])->where('ShortName', $u['short'])->whereNull('deleted_at')->first();
            if ($row) {
                $this->unitIds[$u['short']] = $row->id;
                $existing++;

                continue;
            }
            $id = DB::table('units')->insertGetId([
                'name' => $u['name'],
                'ShortName' => $u['short'],
                'operator' => '*',
                'operator_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->unitIds[$u['short']] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedBrands(): array
    {
        $created = 0;
        $existing = 0;
        foreach ($this->data['brands'] as $name) {
            $row = DB::table('brands')->where('name', $name)->whereNull('deleted_at')->first();
            if ($row) {
                $this->brandIds[$name] = $row->id;
                $existing++;

                continue;
            }
            $id = DB::table('brands')->insertGetId([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->brandIds[$name] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedCategories(): array
    {
        $created = 0;
        $existing = 0;
        $i = 1;
        foreach (array_keys($this->data['categories']) as $name) {
            $code = sprintf('CAT-DEMO2-%02d', $i++);
            $row = DB::table('categories')->where('code', $code)->whereNull('deleted_at')->first();
            if ($row) {
                $this->categoryIds[$code] = $row->id;
                $existing++;

                continue;
            }
            $id = DB::table('categories')->insertGetId([
                'code' => $code,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->categoryIds[$code] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    /**
     * Requires seedUnits()/seedBrands()/seedCategories() to have run first in
     * THIS invocation OR to already exist in the DB from a prior run — this
     * method re-resolves ids by the same deterministic keys rather than
     * trusting in-memory state alone, so it is safe to call standalone.
     */
    public function seedProducts(): array
    {
        $this->reresolveCatalogIds();

        $created = 0;
        $existing = 0;
        $failed = 0;
        $n = 0;
        $catIndex = 0;
        $categoryCodesByIndex = $this->categoryCodes();

        foreach ($this->data['categories'] as $catName => $items) {
            $categoryCode = $categoryCodesByIndex[$catIndex++];
            $categoryId = $this->categoryIds[$categoryCode] ?? null;

            foreach ($items as $j => $productName) {
                $n++;
                $code = sprintf('PR-DEMO2-%03d', $n);

                $existingRow = DB::table('products')->where('code', $code)->whereNull('deleted_at')->first();
                if ($existingRow) {
                    $existing++;

                    continue;
                }

                if (! $categoryId) {
                    $failed++;

                    continue;
                }

                $brandName = $this->data['brands'][($n - 1) % count($this->data['brands'])];
                $brandId = $this->brandIds[$brandName] ?? null;
                $unit = $this->data['units'][($n - 1) % count($this->data['units'])];
                $unitId = $this->unitIds[$unit['short']] ?? null;

                // Deterministic variety (same seed => same values on re-run):
                // cost/price/margin vary by position instead of every product
                // sharing identical numbers.
                $tier = $n % 5; // 0..4
                $baseCost = 40 + ($tier * 35) + (($n * 7) % 60); // HNL
                $margin = 1.35 + ($tier * 0.08);
                $cost = round($baseCost, 2);
                $price = round($cost * $margin, 2);
                $wholesale = round($cost * ($margin - 0.12 > 1.05 ? $margin - 0.12 : 1.1), 2);
                $minPrice = round($cost * 1.05, 2);
                $stockAlert = 5 + ($n % 10);

                try {
                    DB::table('products')->insert([
                        'code' => $code,
                        'gtin' => null,
                        'Type_barcode' => 'CODE128',
                        'name' => $productName,
                        'type' => 'is_single',
                        'cost' => $cost,
                        'price' => $price,
                        'wholesale_price' => $wholesale,
                        'min_price' => $minPrice,
                        'category_id' => $categoryId,
                        'brand_id' => $brandId,
                        'unit_id' => $unitId,
                        'unit_sale_id' => $unitId,
                        'unit_purchase_id' => $unitId,
                        'stock_alert' => $stockAlert,
                        'TaxNet' => 15,
                        'tax_method' => '1',
                        'is_active' => 1,
                        'is_variant' => 0,
                        'is_imei' => 0,
                        'not_selling' => 0,
                        'is_featured' => $n % 10 === 0 ? 1 : 0,
                        'hide_from_online_store' => 0,
                        'points' => 0,
                        'warranty_period' => null,
                        'has_guarantee' => 0,
                        'guarantee_period' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    $failed++;
                }
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed];
    }

    private function reresolveCatalogIds(): void
    {
        foreach ($this->data['units'] as $u) {
            if (! isset($this->unitIds[$u['short']])) {
                $id = DB::table('units')->where('name', $u['name'])->where('ShortName', $u['short'])->whereNull('deleted_at')->value('id');
                if ($id) {
                    $this->unitIds[$u['short']] = $id;
                }
            }
        }
        foreach ($this->data['brands'] as $name) {
            if (! isset($this->brandIds[$name])) {
                $id = DB::table('brands')->where('name', $name)->whereNull('deleted_at')->value('id');
                if ($id) {
                    $this->brandIds[$name] = $id;
                }
            }
        }
        $i = 1;
        foreach (array_keys($this->data['categories']) as $ignored) {
            $code = sprintf('CAT-DEMO2-%02d', $i++);
            if (! isset($this->categoryIds[$code])) {
                $id = DB::table('categories')->where('code', $code)->whereNull('deleted_at')->value('id');
                if ($id) {
                    $this->categoryIds[$code] = $id;
                }
            }
        }
    }

    /** Current row counts for the tables Phase A touches — used for the "before" report. */
    public function currentCounts(): array
    {
        return [
            'Unidades' => DB::table('units')->count(),
            'Marcas' => DB::table('brands')->count(),
            'Categorías' => DB::table('categories')->count(),
            'Productos' => DB::table('products')->count(),
            'Proveedores' => DB::table('providers')->count(),
            'Compras' => DB::table('purchases')->count(),
            'Transferencias' => DB::table('transfers')->count(),
            'Clientes' => DB::table('clients')->count(),
            'Ventas' => DB::table('sales')->count(),
            'Cotizaciones' => DB::table('quotations')->count(),
            'Promociones' => DB::table('promotions')->count(),
        ];
    }

    // ------------------------------------------------------------------
    // Phase B — providers, initial stock, purchases (+ batches/serials), transfers.
    // Adjustments deferred: see class docblock note in DemoTenantSeeder header
    // and the command's Phase B report — AdjustmentController::store() hard-
    // requires inventory_location_id and this tenant has zero inventory_locations
    // provisioned; creating one is an infra decision out of scope for a demo
    // data seeder, not something this class does silently.
    // ------------------------------------------------------------------

    public function seedProviders(): array
    {
        $created = 0;
        $existing = 0;
        foreach ($this->data['providers'] as $i => $name) {
            $row = DB::table('providers')->where('name', $name)->whereNull('deleted_at')->first();
            if ($row) {
                $existing++;

                continue;
            }
            DB::table('providers')->insert([
                'name' => $name,
                'phone' => $this->fakePhone($i),
                'email' => $this->fakeEmail($name),
                'adresse' => 'Zona Comercial, Honduras',
                'country' => 'Honduras',
                'city' => null,
                'tax_number' => null, // fictitious — no real RTN
                'opening_balance' => 0,
                'credit_limit' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    private function fakePhone(int $seed): string
    {
        return '+504 9'.sprintf('%03d', 100 + ($seed * 37) % 900).sprintf('%04d', ($seed * 911) % 10000);
    }

    private function fakeEmail(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));

        return substr($slug, 0, 20).'@demo2.prodex.test';
    }

    /**
     * Every demo product gets a product_warehouse row (qte=0) per active
     * warehouse — mirrors exactly how ProductsController::store() seeds
     * product_warehouse at creation (rows always born at qte=0; real stock
     * comes later from a purchase/opening-stock flow). Without this row
     * pre-existing, PurchasesController/TransferController silently no-op
     * the stock increment (verified in both controllers) — never leaving a
     * dangling qte, but never actually moving stock either.
     */
    public function seedInitialStock(): array
    {
        $productIds = $this->allDemoProductIds();
        $warehouseIds = $this->allWarehouseIds();
        $created = 0;
        $existing = 0;

        if (! $productIds || ! $warehouseIds) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $existingPairs = DB::table('product_warehouse')
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNull('deleted_at')
            ->get(['product_id', 'warehouse_id'])
            ->map(fn ($r) => $r->product_id.':'.$r->warehouse_id)
            ->flip();

        $rows = [];
        foreach ($productIds as $pid) {
            foreach ($warehouseIds as $wid) {
                if ($existingPairs->has($pid.':'.$wid)) {
                    $existing++;

                    continue;
                }
                $rows[] = [
                    'product_id' => $pid,
                    'warehouse_id' => $wid,
                    'product_variant_id' => null,
                    'manage_stock' => 1,
                    'qte' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('product_warehouse')->insert($chunk);
            }
            $created = count($rows);
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    /** Tenant owner (role_id=1) — the same authority level normal UI usage runs as. Required to invoke real controllers outside HTTP. */
    private function demoActingUser(): ?User
    {
        return User::where('role_id', 1)->whereNull('deleted_at')->first();
    }

    /**
     * Reuses the container's EXISTING bound `request` (present even in a
     * console command) and just replaces its input + user resolver, instead
     * of binding a brand-new bare Request as the global singleton. Binding a
     * fresh `Request::create()` as `app()->instance('request', ...)` was
     * tried first and broke Gate::forUser($user)->authorize(): some
     * permission/role resolution in this app depends on context the real
     * bound request carries (route/session state) that a from-scratch
     * Request lacks — with it swapped out, every authorizeForUser() call
     * failed with "This action is unauthorized" even for the real tenant
     * owner. Verified via tinker before this fix; verified working after.
     */
    private function makeControllerRequest(array $payload, $user): Request
    {
        $request = app('request');
        $request->replace($payload);
        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        return $request;
    }

    /**
     * Two designated demo products carry batch/serial tracking so Phase B can
     * produce real lotes/vencimientos and real seriales through the actual
     * purchase flow (BatchService/SerialNumberService), instead of inserting
     * ledger rows directly. Idempotent: only flips the flag when it isn't
     * already set; never touches any other product attribute.
     */
    private function ensureTrackingFlags(): array
    {
        $batchProduct = DB::table('products')->where('code', 'PR-DEMO2-001')->whereNull('deleted_at')->first();
        $serialProduct = DB::table('products')->where('code', 'PR-DEMO2-002')->whereNull('deleted_at')->first();

        if ($batchProduct && ! $batchProduct->is_batch_tracked) {
            DB::table('products')->where('id', $batchProduct->id)->update(['is_batch_tracked' => 1]);
        }
        if ($serialProduct && ! $serialProduct->is_imei) {
            DB::table('products')->where('id', $serialProduct->id)->update(['is_imei' => 1]);
        }

        return [
            'batch_product_id' => $batchProduct->id ?? null,
            'serial_product_id' => $serialProduct->id ?? null,
        ];
    }

    /**
     * 10 purchases via the REAL PurchasesController::store() — never a raw
     * insert — so stock increments, batch ledger rows and serial ledger rows
     * all go through the exact same code path a real user's purchase would.
     * Idempotency: a deterministic marker `[DEMO2-PUR-NN]` appended to the
     * purchase `notes` field (the only free-text column on Purchase — there
     * is no dedicated code/reference column under our control, since `Ref`
     * is always auto-generated by the controller itself). Checked BEFORE
     * calling store() so a re-run never creates a second purchase for an
     * index that already exists.
     */
    public function seedPurchases(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }

        $flags = $this->ensureTrackingFlags();
        $productRows = DB::table('products')->whereIn('code', $this->productCodes())->whereNull('deleted_at')->orderBy('code')->get(['id', 'code', 'cost']);
        if ($productRows->isEmpty()) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }
        $providerIds = DB::table('providers')->whereIn('name', $this->data['providers'])->whereNull('deleted_at')->pluck('id')->values()->all();
        if (! $providerIds) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }
        $warehouseId = min($this->allWarehouseIds() ?: [0]);
        if (! $warehouseId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }
        $defaultUnitId = DB::table('units')->whereNull('deleted_at')->orderBy('id')->value('id');

        $pool = $productRows->values();
        $poolCount = $pool->count();

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('[DEMO2-PUR-%02d]', $n);
            if (DB::table('purchases')->where('notes', 'like', '%'.$marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $statut = $n >= 9 ? 'pending' : 'received';
            $date = now()->subDays(90 - (($n - 1) * 9))->format('Y-m-d');
            $providerId = $providerIds[($n - 1) % count($providerIds)];

            $lineCount = 5 + ($n % 4); // 5..8 lines
            $start = (($n - 1) * 7) % $poolCount;
            $windowIndexes = [];
            for ($k = 0; $k < $lineCount; $k++) {
                $windowIndexes[] = ($start + $k) % $poolCount;
            }

            // The rotation window doesn't reliably land on the two designated
            // batch/serial products (it didn't, for the serial one, before
            // this fix — purchase #2's window started at pool index 7, never
            // covering index 1, PR-DEMO2-002). ADD the designated product's
            // index to the window instead of overwriting an existing slot —
            // overwriting silently dropped whatever product used to be at
            // that slot from this purchase's coverage, which (after a
            // second run recreated the purchase following a delete) left
            // that dropped product's already-transferred-out stock with
            // nothing backing it, going negative. Found and fixed while
            // debugging this exact method.
            if ($n === 1 && $flags['batch_product_id']) {
                $idx = $productRows->search(fn ($p) => $p->id === $flags['batch_product_id']);
                if ($idx !== false && ! in_array($idx, $windowIndexes, true)) {
                    array_unshift($windowIndexes, $idx);
                }
            }
            if ($n === 2 && $flags['serial_product_id']) {
                $idx = $productRows->search(fn ($p) => $p->id === $flags['serial_product_id']);
                if ($idx !== false && ! in_array($idx, $windowIndexes, true)) {
                    array_unshift($windowIndexes, $idx);
                }
            }

            $details = [];
            $grandTotal = 0;

            foreach ($windowIndexes as $k => $poolIndex) {
                $product = $pool[$poolIndex];
                $qty = 10 + (($n + $k) % 6) * 5; // 10..35
                $cost = (float) $product->cost;

                $row = [
                    'purchase_unit_id' => $defaultUnitId,
                    'Unit_cost' => $cost,
                    'tax_percent' => 15,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_Method' => '1',
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'subtotal' => round($cost * $qty, 2),
                    'quantity' => $qty,
                ];

                // Designated batch-tracked product, first purchase only:
                // split its line into 10 lots with varied expiry horizons.
                if ($n === 1 && $product->id === $flags['batch_product_id']) {
                    $row['quantity'] = 100;
                    $row['subtotal'] = round($cost * 100, 2);
                    $batches = [];
                    for ($b = 1; $b <= 10; $b++) {
                        $horizonDays = [30, 45, 60, 90, 120, 180, 270, 365, 545, 730][$b - 1]; // near -> long term, never expired
                        $batches[] = [
                            'batch_no' => sprintf('LOT-DEMO2-%02d', $b),
                            'qty' => 10,
                            'expiry_date' => now()->addDays($horizonDays)->format('Y-m-d'),
                            'mfg_date' => now()->subDays(30)->format('Y-m-d'),
                        ];
                    }
                    $row['batches'] = $batches;
                }

                // Designated serial-tracked product, second purchase only:
                // exactly 10 units, 10 unique serials (count must match qty).
                if ($n === 2 && $product->id === $flags['serial_product_id']) {
                    $row['quantity'] = 10;
                    $row['subtotal'] = round($cost * 10, 2);
                    $row['serial_numbers'] = array_map(fn ($s) => sprintf('SN-DEMO2-%03d', $s), range(1, 10));
                }

                $grandTotal += $row['subtotal'];
                $details[] = $row;
            }

            $payload = [
                'date' => $date,
                'supplier_id' => $providerId,
                'warehouse_id' => $warehouseId,
                'GrandTotal' => round($grandTotal * 1.15, 2),
                'tax_rate' => 15,
                'TaxNet' => round($grandTotal * 0.15, 2),
                'discount' => 0,
                'shipping' => 0,
                'statut' => $statut,
                'notes' => 'Pedido de reabastecimiento '.$marker,
                'details' => $details,
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(PurchasesController::class)->store($request);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Compra {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors ?? []];
    }

    /**
     * 10 transfers via the REAL, CURRENT workflow — never a raw qte update:
     * TransferController::store() [pending, no stock effect] ->
     * TransferWorkflowController::approve() [approval_status=approved ONLY —
     * per the model's own docblock, "approval authorizes the operation but
     * does not move inventory"] -> TransferWorkflowController::dispatchTransfer()
     * [THIS is what actually debits the source warehouse, via
     * TransferWorkflowService::debitLegacySource(), which itself throws
     * "Stock insuficiente" rather than ever going negative].
     *
     * Earlier version of this method called the LEGACY
     * TransferController::approve() (a still-present but superseded method
     * that inline-decrements stock the moment it's called, with no
     * sufficiency check) — that produced 4 products with negative stock in
     * demo01 before this fix, since it moved stock for products that were
     * never actually purchased. Verified fixed: after switching to the
     * workflow controller pair, a run against demo01 produced zero negative
     * rows.
     *
     * Deliberately stops at "dispatched / in transit" (statut=sent,
     * logistics_status=in_transit) and does NOT call
     * TransferLogisticsController::receive() — receiving is a full
     * reconciliation subsystem of its own (request tokens, per-line
     * quantity_good/defective/missing accounting, discrepancies). Completing
     * it automatically for demo data risks generating inconsistent partial-
     * receipt records; "in transit" (stock correctly left the source, not
     * yet credited to the destination) is itself a real, valid, coherent
     * state — visible and testable in the Transferencias view — and is the
     * same category of decision as deferring Adjustments (see class notes).
     *
     * Idempotency marker: `[DEMO2-TRF-NN]` in the transfer `notes` field.
     */
    public function seedTransfers(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        $user = $this->demoActingUser();
        $warehouseIds = $this->allWarehouseIds();
        if (! $user || count($warehouseIds) < 2) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => []];
        }

        $fromWarehouse = min($warehouseIds);
        $otherWarehouses = array_values(array_diff($warehouseIds, [$fromWarehouse]));
        $defaultUnitId = DB::table('units')->whereNull('deleted_at')->orderBy('id')->value('id');

        // Only products that ACTUALLY carry stock in the source warehouse
        // right now are eligible — never assume a product was purchased just
        // because seedPurchases() ran earlier in this same invocation ( a
        // partial prior run, or a differently-configured re-run, might not
        // have touched it). A safety margin (transfer at most half of what's
        // on hand, minimum 1) keeps this from ever approaching zero.
        $stocked = DB::table('product_warehouse')
            ->join('products', 'products.id', '=', 'product_warehouse.product_id')
            ->where('product_warehouse.warehouse_id', $fromWarehouse)
            ->where('products.code', 'like', 'PR-DEMO2-%')
            ->whereNull('product_warehouse.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('product_warehouse.qte', '>=', 4)
            ->orderBy('products.code')
            ->get(['product_warehouse.product_id', 'product_warehouse.qte']);

        if ($stocked->isEmpty()) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Transferencias: ningún producto demo tiene stock suficiente en el almacén origen todavía — ejecuta seedPurchases() primero.']];
        }

        $pool = $stocked->values();
        $poolCount = $pool->count();

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('[DEMO2-TRF-%02d]', $n);
            if (DB::table('transfers')->where('notes', 'like', '%'.$marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $toWarehouse = $otherWarehouses[($n - 1) % count($otherWarehouses)];
            $date = now()->subDays(60 - (($n - 1) * 6))->format('Y-m-d');

            $lineCount = min(3, $poolCount);
            $start = (($n - 1) * 3) % $poolCount;
            $details = [];
            for ($k = 0; $k < $lineCount; $k++) {
                $row = $pool[($start + $k) % $poolCount];
                $safeQty = max(1, min(10, (int) floor(((float) $row->qte) / 2)));
                $details[] = [
                    'purchase_unit_id' => $defaultUnitId,
                    'product_id' => $row->product_id,
                    'product_variant_id' => null,
                    'quantity' => $safeQty,
                    'Unit_cost' => 0,
                    'tax_percent' => 0,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_Method' => '1',
                    'subtotal' => 0,
                ];
            }

            $payload = [
                'transfer' => [
                    'date' => $date,
                    'from_warehouse' => $fromWarehouse,
                    'to_warehouse' => $toWarehouse,
                    'tax_rate' => 0,
                    'TaxNet' => 0,
                    'discount' => 0,
                    'shipping' => 0,
                    'statut' => 'pending',
                    'notes' => 'Reabastecimiento entre sucursales '.$marker,
                ],
                'GrandTotal' => 0,
                'details' => $details,
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(TransferController::class)->store($request);

                $transferId = DB::table('transfers')->where('notes', 'like', '%'.$marker)->whereNull('deleted_at')->orderByDesc('id')->value('id');
                if (! $transferId) {
                    $failed++;
                    $errors[] = "Transferencia {$marker}: store() no lanzó excepción pero no se encontró el registro por su marcador.";

                    continue;
                }

                $workflow = app(TransferWorkflowController::class);
                $workflow->approve($this->makeControllerRequest([], $user), $transferId);
                $workflow->dispatchTransfer($this->makeControllerRequest([], $user), $transferId);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Transferencia {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    // ------------------------------------------------------------------
    // Phase C — clientes, ventas (POS), cotizaciones, promociones.
    // Reservas: deliberately NOT seeded — see docblock above seedReservasDecision().
    // ------------------------------------------------------------------

    /**
     * 10 fictional clients, raw insert. Audited: ClientController::store()
     * has zero side effects beyond the row itself (no portal/User account,
     * no points-ledger table, no journal entry — `points` is just a plain
     * column). `code` (int, required, no DB uniqueness constraint) is
     * assigned the same way ClientController::getNumberOrder() does:
     * max(existing code)+1, incremented per row inserted this run.
     * Idempotency: exact `name` match against DemoPersonas' fixed 10-name
     * `clients` list — same technique already used for brands/units/providers.
     * tax_number left null: not enforced at DB level, sidesteps the
     * Honduras-only 14-digit fiscal validation entirely (that validation
     * lives in the controller, which this method does not call).
     */
    public function seedClients(): array
    {
        $created = 0;
        $existing = 0;

        $nextCode = ((int) DB::table('clients')->max('code')) + 1;
        $cities = ['Tegucigalpa', 'San Pedro Sula', 'La Ceiba', 'Comayagua', 'Choluteca', 'Danlí', 'Siguatepeque', 'Roatán', 'Juticalpa', 'Santa Rosa de Copán'];

        foreach ($this->data['clients'] as $i => $name) {
            $row = DB::table('clients')->where('name', $name)->whereNull('deleted_at')->first();
            if ($row) {
                $this->clientIds[$name] = $row->id;
                $existing++;

                continue;
            }

            $createdAt = now()->subDays(150 - ($i * 12));
            $id = DB::table('clients')->insertGetId([
                'name' => $name,
                'firstname' => null,
                'lastname' => null,
                'code' => $nextCode++,
                'adresse' => 'Zona Comercial, '.$cities[$i % count($cities)],
                'phone' => $this->fakePhone(100 + $i),
                'email' => $this->fakeEmail($name),
                'country' => 'Honduras',
                'city' => $cities[$i % count($cities)],
                'state' => null,
                'zip' => null,
                'tax_number' => null, // fictitious — no real RTN, left null to skip fiscal format validation entirely
                'is_royalty_eligible' => 1,
                'points' => 0,
                'opening_balance' => 0,
                'credit_limit' => $i % 3 === 0 ? 10000 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $this->clientIds[$name] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    /** Re-resolves demo client ids by name — never assumes seedClients() ran earlier in this same process. */
    private function demoClientIds(): array
    {
        return DB::table('clients')->whereIn('name', $this->data['clients'])->whereNull('deleted_at')->pluck('id', 'name')->all();
    }

    /**
     * Products eligible for a demo sale: DEMO catalog, NOT batch/serial
     * tracked (PR-DEMO2-001/002 — those two are deliberately left untouched
     * by Sales so Fase B's purchased lots/serials stay available for their
     * own views, per explicit user instruction), with live stock in the
     * given warehouse right now. Re-queried fresh on every call — the POS
     * controller physically decrements product_warehouse.qte the moment a
     * sale is created, so a later call in the same loop always sees the
     * real, post-decrement quantity.
     */
    private function eligibleSaleProducts(int $warehouseId)
    {
        return DB::table('product_warehouse')
            ->join('products', 'products.id', '=', 'product_warehouse.product_id')
            ->where('product_warehouse.warehouse_id', $warehouseId)
            ->where('products.code', 'like', 'PR-DEMO2-%')
            ->where('products.is_batch_tracked', 0)
            ->where('products.is_imei', 0)
            ->whereNull('product_warehouse.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('product_warehouse.qte', '>=', 4)
            ->orderBy('products.code')
            ->get([
                'product_warehouse.product_id',
                'product_warehouse.qte',
                'products.price',
                'products.unit_sale_id',
            ])
            ->values();
    }

    /**
     * 10 sales via the REAL, current POS entry point — PosController::CreatePOS().
     * Audited: SalesController::store() is hard-blocked (403 MANUAL_SALE_POS_ONLY,
     * dead entry point) — CreatePOS() is the only live path, and it owns every
     * side effect (stock decrement, points, payment_statut, PaymentSale rows).
     * Never insert Sale/SaleDetail/PaymentSale directly.
     *
     * Idempotency marker: a deterministic `sale_uuid` (the column IS the
     * controller's own built-in idempotency key — see the short-circuit at
     * the top of CreatePOS()) — checked BEFORE calling the controller.
     *
     * Payment variety (n mod 3): 'paid' (single Cash payment for the full
     * total), 'partial' (Cash payment for half the total), 'unpaid' (empty
     * `payments` array — valid per CreatePOS's own validation, produces
     * payment_statut='unpaid' with zero PaymentSale rows and zero Account
     * movement). No `account_id` is ever sent, so no Account.balance is
     * ever touched by this seeder, by design — the user's financial
     * caution requirement.
     *
     * `cash_drawer_id` and `warehouse_id` are fixed to the tenant's single
     * active drawer/warehouse pairing (queried fresh, never hardcoded to
     * id=1) — CreatePOS hard-requires an active drawer bound to the target
     * warehouse (UserOperationalAssignmentService::validateRequestedAssignment).
     *
     * `date`/`created_at` are hardcoded to now() inside CreatePOS (no payload
     * field controls them) — backdated via a plain UPDATE AFTER the
     * transaction commits, on sales/sale_details/payment_sales rows for that
     * sale id only. Display-only fields, no side effect re-triggers.
     */
    public function seedSales(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => []];
        }

        $drawer = DB::table('cash_drawers')->where('is_active', 1)->whereNull('deleted_at')->orderBy('id')->first();
        if (! $drawer) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Ventas: no existe ninguna caja física activa — CreatePOS() la exige incondicionalmente.']];
        }
        $warehouseId = (int) $drawer->warehouse_id;

        $clientIds = array_values($this->demoClientIds());
        if (! $clientIds) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Ventas: ningún cliente DEMO existe todavía — ejecuta seedClients() primero.']];
        }

        $assignmentService = app(UserOperationalAssignmentService::class);

        for ($n = 1; $n <= 10; $n++) {
            $marker = self::SALE_UUID_PREFIX.sprintf('%02d', $n).str_repeat('0', 36 - strlen(self::SALE_UUID_PREFIX) - 2);

            if (DB::table('sales')->where('sale_uuid', $marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $pool = $this->eligibleSaleProducts($warehouseId);
            if ($pool->isEmpty()) {
                $failed++;
                $errors[] = "Venta {$marker}: ningún producto DEMO tiene stock suficiente en el almacén activo — ejecuta seedPurchases() primero.";

                continue;
            }

            $lineCount = min(2 + ($n % 3), $pool->count());
            $start = (($n - 1) * 3) % $pool->count();

            $details = [];
            $subtotalSum = 0.0;
            for ($k = 0; $k < $lineCount; $k++) {
                $row = $pool[($start + $k) % $pool->count()];
                $qty = max(1, min(3, (int) floor(((float) $row->qte) * 0.15)));
                $price = (float) $row->price;
                $subtotal = round($price * $qty, 2);
                $subtotalSum += $subtotal;

                $details[] = [
                    'product_id' => $row->product_id,
                    'product_variant_id' => null,
                    'sale_unit_id' => $row->unit_sale_id,
                    'quantity' => $qty,
                    'Unit_price' => $price,
                    'subtotal' => $subtotal,
                    'tax_percent' => 15,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_Method' => '1',
                    'imei_number' => null,
                ];
            }

            $taxAmount = round($subtotalSum * 0.15, 2);
            $grandTotal = round($subtotalSum + $taxAmount, 2);

            $paymentKind = match ($n % 3) {
                1 => 'paid',
                2 => 'partial',
                default => 'unpaid',
            };
            // CreatePOS() validates 'payments' => 'required|array' — Laravel's
            // `required` rule treats an EMPTY array as absent and rejects it
            // (found the hard way: the first real run against demo01 failed
            // 3/10 sales with "El campo payments es obligatorio" from `[]`).
            // A single zero-amount line satisfies `required` (0 is not an
            // empty value) while producing zero side effects: normalizePosPayments()
            // computes applied_amount=0 for it, and CreatePOS only creates a
            // PaymentSale when applied_amount > 0 — so this is still the true
            // unpaid path (due=GrandTotal, payment_statut='unpaid', 0 PaymentSale rows).
            $payments = match ($paymentKind) {
                'paid' => [['payment_method_id' => 2, 'amount' => $grandTotal]],
                'partial' => [['payment_method_id' => 2, 'amount' => round($grandTotal * 0.5, 2)]],
                'unpaid' => [['payment_method_id' => 2, 'amount' => 0]],
            };

            $clientId = $clientIds[($n - 1) % count($clientIds)];
            $daysAgo = 90 - (($n - 1) * 3);
            $saleDate = now()->subDays($daysAgo);

            $payload = [
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'cash_drawer_id' => $drawer->id,
                'payments' => $payments,
                'TaxNet' => $taxAmount,
                'discount' => 0,
                'discount_Method' => '2',
                'shipping' => 0,
                'GrandTotal' => $grandTotal,
                'notes' => 'Venta DEMO '.$marker,
                'sale_uuid' => $marker,
                'details' => $details,
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(PosController::class)->CreatePOS($request, $assignmentService);

                $saleId = DB::table('sales')->where('sale_uuid', $marker)->whereNull('deleted_at')->value('id');
                if (! $saleId) {
                    $failed++;
                    $errors[] = "Venta {$marker}: CreatePOS() no lanzó excepción pero no se encontró el registro por su sale_uuid.";

                    continue;
                }

                DB::table('sales')->where('id', $saleId)->update(['date' => $saleDate->format('Y-m-d'), 'created_at' => $saleDate, 'updated_at' => $saleDate]);
                DB::table('sale_details')->where('sale_id', $saleId)->update(['date' => $saleDate->format('Y-m-d'), 'created_at' => $saleDate, 'updated_at' => $saleDate]);
                DB::table('payment_sales')->where('sale_id', $saleId)->update(['date' => $saleDate->format('Y-m-d'), 'created_at' => $saleDate, 'updated_at' => $saleDate]);

                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Venta {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * 10 quotations via the REAL QuotationsController::store(). Audited:
     * store() explicitly has NO stock effect ("quotations are proposals" —
     * confirmed by inline comments at the real controller's store()/update()/
     * destroy() methods). None are converted to a sale in this seeder — a
     * conversion is a full real Sale (PosController::CreatePOS, same as
     * seedSales()) with the exact same stock/payment side effects, and the
     * user's instruction was explicitly NOT to convert all of them; leaving
     * all 10 as visible, unconverted quotations keeps the Cotizaciones view
     * populated without doubling up on Sale side effects already covered by
     * seedSales(). Idempotency marker: `[DEMO2-QUO-NN]` in `notes`, same
     * pattern as Purchase/Transfer.
     */
    public function seedQuotations(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => []];
        }

        $warehouseId = (int) DB::table('cash_drawers')->where('is_active', 1)->whereNull('deleted_at')->orderBy('id')->value('warehouse_id');
        if (! $warehouseId) {
            $warehouseId = min($this->allWarehouseIds() ?: [0]);
        }
        if (! $warehouseId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Cotizaciones: no hay warehouse disponible.']];
        }

        $clientIds = array_values($this->demoClientIds());
        if (! $clientIds) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Cotizaciones: ningún cliente DEMO existe todavía — ejecuta seedClients() primero.']];
        }

        $productRows = DB::table('products')->whereIn('code', $this->productCodes())->whereNull('deleted_at')->orderBy('code')->get(['id', 'price', 'unit_sale_id']);
        if ($productRows->isEmpty()) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Cotizaciones: catálogo DEMO no existe todavía.']];
        }
        $pool = $productRows->values();
        $poolCount = $pool->count();

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('[DEMO2-QUO-%02d]', $n);
            if (DB::table('quotations')->where('notes', 'like', '%'.$marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $lineCount = 3 + ($n % 3); // 3..5
            $start = (($n - 1) * 5) % $poolCount;

            $details = [];
            $subtotalSum = 0.0;
            for ($k = 0; $k < $lineCount; $k++) {
                $product = $pool[($start + $k) % $poolCount];
                $qty = 1 + (($n + $k) % 5); // 1..5
                $price = (float) $product->price;
                $subtotal = round($price * $qty, 2);
                $subtotalSum += $subtotal;

                $details[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'sale_unit_id' => $product->unit_sale_id,
                    'quantity' => $qty,
                    'Unit_price' => $price,
                    'subtotal' => $subtotal,
                    'tax_percent' => 15,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_Method' => '1',
                    'imei_number' => null,
                ];
            }

            $taxAmount = round($subtotalSum * 0.15, 2);
            $grandTotal = round($subtotalSum + $taxAmount, 2);
            $statut = $n % 2 === 0 ? 'sent' : 'pending';
            $clientId = $clientIds[($n - 1) % count($clientIds)];
            $daysAgo = 45 - (($n - 1) * 4); // 45..9, last few land "recent"
            $date = now()->subDays(max(0, $daysAgo))->format('Y-m-d');

            $payload = [
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'date' => $date,
                'statut' => $statut,
                'GrandTotal' => $grandTotal,
                'tax_rate' => 15,
                'TaxNet' => $taxAmount,
                'Discount' => 0,
                'shipping' => 0,
                'notes' => 'Cotización DEMO '.$marker,
                'details' => $details,
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(QuotationsController::class)->store($request);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Cotización {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * 10 promotions via the REAL PromotionsController::store(). Audited: the
     * store() method's only side effects are the promotion row itself plus
     * two pivot syncs (promotion_warehouse, promotion_products) — no price
     * mutation, no notifications. Idempotency marker: exact `code` match
     * (`DEMO2-PROMO-NN`), checked before calling store() — mirrors the
     * category/product `code` lookup pattern.
     *
     * Distribution across the 10: 4 currently active (started in the past,
     * ends in the future), 3 future (starts in the future), 3 already ended
     * (both dates in the past) — `is_active` stays true for all; active vs.
     * future vs. ended is purely derived from date range, per the audited
     * model (no separate status column).
     */
    public function seedPromotions(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => []];
        }

        $warehouseIds = $this->allWarehouseIds();
        $productIds = $this->allDemoProductIds();

        $names = [
            'Descuento Apertura de Temporada', 'Oferta Relámpago Fin de Semana', 'Promo Combo Familiar',
            'Descuento Cliente Frecuente', 'Rebaja de Inventario', 'Promoción Día de Pago',
            'Oferta Especial Aniversario', 'Descuento Compra Mayor', 'Promo Liquidación', 'Oferta Bienvenida',
        ];

        // n=1..4 active, n=5..7 future, n=8..10 ended.
        $schedule = function (int $n): array {
            if ($n <= 4) {
                return [now()->subDays(10 + $n)->format('Y-m-d'), now()->addDays(20 + $n)->format('Y-m-d')];
            }
            if ($n <= 7) {
                return [now()->addDays(5 + $n)->format('Y-m-d'), now()->addDays(35 + $n)->format('Y-m-d')];
            }

            return [now()->subDays(60 + $n)->format('Y-m-d'), now()->subDays(5 + $n)->format('Y-m-d')];
        };

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('DEMO2-PROMO-%02d', $n);
            if (DB::table('promotions')->where('code', $marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            [$startsAt, $endsAt] = $schedule($n);
            $isPercentage = $n % 2 === 1;
            $productScope = $n % 2 === 0 ? 'specific' : 'all';

            $payload = [
                'name' => $names[$n - 1],
                'code' => $marker,
                'description' => 'Promoción de demostración generada por Fase C.',
                'kind' => 'discount',
                'discount_type' => $isPercentage ? 'percentage' : 'fixed',
                'discount_value' => $isPercentage ? (10 + ($n % 4) * 5) : (50 + ($n % 3) * 25),
                'is_active' => true,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'product_scope' => $productScope,
                'priority' => $n,
                'stackable' => false,
                'warehouse_ids' => $warehouseIds,
                'product_ids' => $productScope === 'specific' ? array_slice($productIds, (($n - 1) * 5) % max(1, count($productIds)), 5) : [],
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(PromotionsController::class)->store($request);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Promoción {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * RESERVAS — deliberately NOT seeded. NO-GO decision, documented instead
     * of code, same class of decision as skipping Adjustments in Fase B.
     *
     * Audited: Booking model + BookingController exist and are a real
     * appointment-booking module (customer_id -> Client, product_id ->
     * Product, status pending/confirmed/cancelled/completed). store() has no
     * stock/payment side effects of its own. But:
     *  1. Its routes are gated behind `tenant.feature:bookings`
     *     (routes/tenant_api.php) and demo01's active plan ("Emprendedor")
     *     does NOT include that feature — TenantLimitsService->hasFeature
     *     ('bookings') returns false for this tenant right now. Seeding
     *     bookings anyway would misrepresent demo01 as having a plan
     *     entitlement it does not have, and no real user could reach the
     *     view that shows them.
     *  2. demo01 has exactly one is_service product total, and it is not
     *     part of the Fase A/B DEMO2 catalog (100% physical goods) — there
     *     is nothing realistic to book against even if the gate were
     *     bypassed.
     *
     * No seedReservas() method exists. This decision is surfaced to the
     * operator via the plan()/currentCounts() output and the command's
     * summary table (target=10, existing=0, to_create=0, skipped=10).
     */
    private function seedReservasDecision(): void
    {
    }
}
