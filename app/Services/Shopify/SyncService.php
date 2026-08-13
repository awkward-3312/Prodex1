<?php

namespace App\Services\Shopify;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client as Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\ShopifyLog;
use App\Models\ShopifyMapping;
use App\Models\ShopifyStore;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Shopify sync engine, scoped to a single ShopifyStore.
 *
 * All batch methods are cursor-based and bounded so they can run inside a web
 * request: the caller loops while `has_more` is true, passing back the cursor
 * (`last_id` for local pushes, `page_info` for remote pulls).
 */
class SyncService
{
    private ShopifyStore $store;
    private Client $client;

    public function __construct(ShopifyStore $store, ?Client $client = null)
    {
        $this->store = $store;
        $this->client = $client ?: Client::forStore($store);
    }

    public static function forStore(ShopifyStore $store): self
    {
        return new self($store);
    }

    // -----------------------------------------------------------------
    // Connection / store metadata
    // -----------------------------------------------------------------

    public function testConnection(): array
    {
        $res = $this->client->get('shop.json');
        if (! $res->successful()) {
            return ['ok' => false, 'status' => $res->status(), 'error' => $res->error()];
        }

        $shop = $res->json()['shop'] ?? [];

        return [
            'ok' => true,
            'shop' => [
                'name' => $shop['name'] ?? null,
                'domain' => $shop['myshopify_domain'] ?? null,
                'currency' => $shop['currency'] ?? null,
                'plan' => $shop['plan_display_name'] ?? null,
            ],
        ];
    }

    public function fetchLocations(): array
    {
        $res = $this->client->get('locations.json');
        if (! $res->successful()) {
            return ['ok' => false, 'error' => $res->error()];
        }

        $locations = array_map(fn ($l) => [
            'id' => $l['id'],
            'name' => $l['name'],
            'active' => (bool) ($l['active'] ?? true),
        ], $res->json()['locations'] ?? []);

        return ['ok' => true, 'locations' => $locations];
    }

    /**
     * Ensure the store has a Shopify location id for inventory sync.
     */
    private function resolveLocationId(): ?int
    {
        if ($this->store->location_id) {
            return (int) $this->store->location_id;
        }

        $result = $this->fetchLocations();
        if (! empty($result['ok']) && ! empty($result['locations'])) {
            $first = $result['locations'][0];
            $this->store->location_id = (int) $first['id'];
            $this->store->location_name = (string) $first['name'];
            $this->store->save();

            return (int) $first['id'];
        }

        return null;
    }

    /**
     * Register the webhooks Stocky consumes. Idempotent (skips existing topic+address pairs).
     */
    public function registerWebhooks(string $callbackUrl): array
    {
        $topics = [
            'orders/create', 'orders/updated',
            'products/update', 'products/delete',
            'customers/create', 'customers/update',
            'app/uninstalled',
        ];

        $existing = [];
        $res = $this->client->get('webhooks.json', ['limit' => 250]);
        if ($res->successful()) {
            foreach ($res->json()['webhooks'] ?? [] as $hook) {
                $existing[($hook['topic'] ?? '').'|'.($hook['address'] ?? '')] = true;
            }
        }

        $created = [];
        $failed = [];
        foreach ($topics as $topic) {
            if (isset($existing[$topic.'|'.$callbackUrl])) {
                continue;
            }
            $create = $this->client->post('webhooks.json', [
                'webhook' => ['topic' => $topic, 'address' => $callbackUrl, 'format' => 'json'],
            ]);
            if ($create->successful()) {
                $created[] = $topic;
            } else {
                $failed[$topic] = $create->error();
            }
        }

        $this->log('webhooks.register', empty($failed) ? 'info' : 'warning', 'Webhooks registered', [
            'address' => $callbackUrl, 'created' => $created, 'failed' => $failed,
        ]);

        return ['ok' => empty($failed), 'created' => $created, 'failed' => $failed];
    }

    // -----------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------

    /**
     * Push a batch of local products to Shopify (create or update).
     */
    public function pushProducts(bool $onlyUnsynced, int $startAfterId = 0, int $batch = 15): array
    {
        $storeId = (int) $this->store->id;

        $query = Product::whereNull('deleted_at')
            ->where('id', '>', $startAfterId)
            ->orderBy('id');

        if ($onlyUnsynced) {
            $mapped = ShopifyMapping::where('shopify_store_id', $storeId)
                ->where('entity_type', ShopifyMapping::TYPE_PRODUCT)
                ->pluck('local_id');
            $query->whereNotIn('id', $mapped);
        }

        $products = $query->limit($batch)->get();

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($products as $product) {
            $lastId = (int) $product->id;
            try {
                $result = $this->pushSingleProduct($product);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $failed++;
                    $errors[] = ['product_id' => $product->id, 'name' => $product->name, 'error' => $result];
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['product_id' => $product->id, 'name' => $product->name, 'error' => $e->getMessage()];
            }
        }

        $hasMore = $products->count() === $batch;
        if (! $hasMore) {
            $this->store->last_sync_at = now();
            $this->store->save();
        }

        $this->log('products.push', $failed ? 'warning' : 'info', "Products push batch: {$created} created, {$updated} updated, {$failed} failed", [
            'start_after_id' => $startAfterId, 'last_id' => $lastId, 'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $products->count(),
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return string 'created' | 'updated' | error message
     */
    public function pushSingleProduct(Product $product): string
    {
        $storeId = (int) $this->store->id;
        $payload = $this->buildProductPayload($product);

        $shopifyId = ShopifyMapping::shopifyId($storeId, ShopifyMapping::TYPE_PRODUCT, (int) $product->id);

        if ($shopifyId) {
            $res = $this->client->put("products/{$shopifyId}.json", ['product' => $payload]);
            if ($res->status() === 404) {
                // Deleted remotely -> drop stale mappings (product + its variants)
                // and rebuild the payload so it no longer carries dead remote ids
                ShopifyMapping::where('shopify_store_id', $storeId)
                    ->where('entity_type', ShopifyMapping::TYPE_PRODUCT)
                    ->where('local_id', $product->id)
                    ->delete();
                $variantIds = ProductVariant::where('product_id', $product->id)->pluck('id');
                if ($variantIds->isNotEmpty()) {
                    ShopifyMapping::where('shopify_store_id', $storeId)
                        ->where('entity_type', ShopifyMapping::TYPE_VARIANT)
                        ->whereIn('local_id', $variantIds)
                        ->delete();
                }
                $payload = $this->buildProductPayload($product);
                $shopifyId = null;
            } elseif (! $res->successful()) {
                return (string) $res->error();
            } else {
                $this->storeProductMappings($product, $res->json()['product'] ?? []);

                return 'updated';
            }
        }

        if (! $shopifyId) {
            $res = $this->client->post('products.json', ['product' => $payload]);
            if (! $res->successful()) {
                return (string) $res->error();
            }
            $this->storeProductMappings($product, $res->json()['product'] ?? []);

            return 'created';
        }

        return 'updated';
    }

    private function buildProductPayload(Product $product): array
    {
        $brand = $product->brand_id ? Brand::find($product->brand_id) : null;
        $category = $product->category_id ? Category::find($product->category_id) : null;

        $payload = [
            'title' => (string) $product->name,
            'body_html' => (string) ($product->note ?? ''),
            'vendor' => $brand ? (string) $brand->name : '',
            'product_type' => $category ? (string) $category->name : '',
            'status' => ((int) $product->is_active === 1 && ! $product->not_selling) ? 'active' : 'draft',
        ];

        $storeId = (int) $this->store->id;

        if ((int) $product->is_variant === 1) {
            $variants = [];
            $optionValues = [];
            foreach (ProductVariant::where('product_id', $product->id)->whereNull('deleted_at')->orderBy('id')->get() as $variant) {
                $optionValues[] = (string) $variant->name;
                $row = [
                    'option1' => (string) $variant->name,
                    'sku' => (string) $variant->code,
                    'barcode' => (string) ($variant->gtin ?? ''),
                    'price' => (string) round((float) $variant->price, 2),
                    'inventory_management' => 'shopify',
                ];
                // Keep the remote variant id on updates — omitting it would make
                // Shopify delete + recreate variants, resetting their inventory.
                $existingId = ShopifyMapping::shopifyId($storeId, ShopifyMapping::TYPE_VARIANT, (int) $variant->id);
                if ($existingId) {
                    $row['id'] = $existingId;
                }
                $variants[] = $row;
            }
            if (! empty($variants)) {
                $payload['options'] = [['name' => 'Variant', 'values' => $optionValues]];
                $payload['variants'] = $variants;
            }
        } else {
            $row = [
                'sku' => (string) $product->code,
                'barcode' => (string) ($product->gtin ?? ''),
                'price' => (string) round((float) $product->price, 2),
                'weight' => (float) ($product->weight ?? 0),
                'weight_unit' => 'kg',
                'inventory_management' => 'shopify',
            ];
            $mapping = ShopifyMapping::where('shopify_store_id', $storeId)
                ->where('entity_type', ShopifyMapping::TYPE_PRODUCT)
                ->where('local_id', $product->id)
                ->first();
            if ($mapping && ! empty(($mapping->extra ?? [])['variant_id'])) {
                $row['id'] = (int) $mapping->extra['variant_id'];
            }
            $payload['variants'] = [$row];
        }

        return $payload;
    }

    /**
     * Persist product + variant mappings (incl. inventory_item_id) from a Shopify product payload.
     */
    private function storeProductMappings(Product $product, array $remote): void
    {
        if (empty($remote['id'])) {
            return;
        }
        $storeId = (int) $this->store->id;

        ShopifyMapping::put($storeId, ShopifyMapping::TYPE_PRODUCT, (int) $product->id, (int) $remote['id'], [
            'handle' => $remote['handle'] ?? null,
            // Simple products keep their single variant identifiers on the product mapping
            'variant_id' => ! $product->is_variant ? ($remote['variants'][0]['id'] ?? null) : null,
            'inventory_item_id' => ! $product->is_variant ? ($remote['variants'][0]['inventory_item_id'] ?? null) : null,
        ]);

        if ((int) $product->is_variant === 1) {
            $localVariants = ProductVariant::where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy(fn ($v) => (string) $v->code);
            foreach ($remote['variants'] ?? [] as $remoteVariant) {
                $sku = (string) ($remoteVariant['sku'] ?? '');
                $local = $sku !== '' ? $localVariants->get($sku) : null;
                if ($local) {
                    ShopifyMapping::put($storeId, ShopifyMapping::TYPE_VARIANT, (int) $local->id, (int) $remoteVariant['id'], [
                        'product_shopify_id' => (int) $remote['id'],
                        'inventory_item_id' => $remoteVariant['inventory_item_id'] ?? null,
                    ]);
                }
            }
        }
    }

    /**
     * Pull one page of Shopify products into Stocky (link by mapping, then SKU; create if unknown).
     */
    public function pullProducts(?string $pageInfo = null, int $perPage = 50): array
    {
        $storeId = (int) $this->store->id;

        $query = $pageInfo
            ? ['limit' => $perPage, 'page_info' => $pageInfo]
            : ['limit' => $perPage, 'status' => 'active,draft'];

        $res = $this->client->get('products.json', $query);
        if (! $res->successful()) {
            return ['ok' => false, 'error' => $res->error()];
        }

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($res->json()['products'] ?? [] as $remote) {
            try {
                $result = $this->importRemoteProduct($remote);
                $result === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['shopify_product_id' => $remote['id'] ?? null, 'error' => $e->getMessage()];
            }
        }

        $next = Client::nextPageInfo($res);

        $this->log('products.pull', $failed ? 'warning' : 'info', "Products pull page: {$created} created, {$updated} updated, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'next_page_info' => $next,
            'has_more' => $next !== null,
        ];
    }

    /**
     * @return string 'created' | 'updated'
     */
    private function importRemoteProduct(array $remote): string
    {
        $storeId = (int) $this->store->id;
        $shopifyId = (int) $remote['id'];
        $variants = $remote['variants'] ?? [];
        $isVariant = count($variants) > 1
            || (count($variants) === 1 && ($variants[0]['title'] ?? 'Default Title') !== 'Default Title');

        $localId = ShopifyMapping::localId($storeId, ShopifyMapping::TYPE_PRODUCT, $shopifyId);
        $product = $localId ? Product::find($localId) : null;

        // Link by SKU when not mapped yet
        if (! $product) {
            foreach ($variants as $rv) {
                $sku = trim((string) ($rv['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $product = Product::whereNull('deleted_at')->where('code', $sku)->first();
                if (! $product) {
                    $localVariant = ProductVariant::whereNull('deleted_at')->where('code', $sku)->first();
                    if ($localVariant) {
                        $product = Product::whereNull('deleted_at')->find($localVariant->product_id);
                    }
                }
                if ($product) {
                    break;
                }
            }
        }

        $categoryId = $this->resolveCategoryId((string) ($remote['product_type'] ?? ''));
        $brandId = $this->resolveBrandId((string) ($remote['vendor'] ?? ''));

        $isNew = false;
        if (! $product) {
            $isNew = true;
            $firstVariant = $variants[0] ?? [];
            $product = new Product();
            // 'type' (not is_variant) is the discriminator the rest of the app checks
            $product->type = $isVariant ? 'is_variant' : 'is_single';
            $product->name = (string) ($remote['title'] ?? 'Shopify product');
            $product->code = trim((string) ($firstVariant['sku'] ?? '')) !== ''
                ? trim((string) $firstVariant['sku'])
                : 'SHF-'.$shopifyId;
            $product->Type_barcode = 'CODE128';
            $product->price = (float) ($firstVariant['price'] ?? 0);
            $product->cost = 0;
            $product->wholesale_price = 0;
            $product->min_price = 0;
            $product->category_id = $categoryId;
            $product->brand_id = $brandId ?: null;
            $product->unit_id = $this->defaultUnitId();
            $product->unit_sale_id = $product->unit_id;
            $product->unit_purchase_id = $product->unit_id;
            $product->tax_method = '1';
            $product->stock_alert = 0;
            $product->is_variant = $isVariant ? 1 : 0;
            $product->is_active = ($remote['status'] ?? 'active') === 'active' ? 1 : 0;
            $product->note = strip_tags((string) ($remote['body_html'] ?? '')) ?: null;
            $product->save();

            // Stock rows for every warehouse
            foreach (Warehouse::whereNull('deleted_at')->pluck('id') as $warehouseId) {
                if ($isVariant) {
                    continue; // created per-variant below
                }
                product_warehouse::firstOrCreate([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => null,
                ], ['qte' => 0, 'manage_stock' => 1]);
            }
        } else {
            $product->name = (string) ($remote['title'] ?? $product->name);
            if ($categoryId) {
                $product->category_id = $categoryId;
            }
            if ($brandId) {
                $product->brand_id = $brandId;
            }
            if ($isVariant && (int) $product->is_variant !== 1) {
                $product->type = 'is_variant';
                $product->is_variant = 1;
            }
            if (! $isVariant && isset($variants[0]['price'])) {
                $product->price = (float) $variants[0]['price'];
            }
            $product->save();
        }

        ShopifyMapping::put($storeId, ShopifyMapping::TYPE_PRODUCT, (int) $product->id, $shopifyId, [
            'handle' => $remote['handle'] ?? null,
            'variant_id' => ! $isVariant ? ($variants[0]['id'] ?? null) : null,
            'inventory_item_id' => ! $isVariant ? ($variants[0]['inventory_item_id'] ?? null) : null,
        ]);

        if ($isVariant) {
            foreach ($variants as $rv) {
                $sku = trim((string) ($rv['sku'] ?? ''));
                $existingLocalId = ShopifyMapping::localId($storeId, ShopifyMapping::TYPE_VARIANT, (int) $rv['id']);
                $localVariant = $existingLocalId ? ProductVariant::find($existingLocalId) : null;
                if (! $localVariant && $sku !== '') {
                    $localVariant = ProductVariant::where('product_id', $product->id)
                        ->whereNull('deleted_at')
                        ->where('code', $sku)
                        ->first();
                }
                if (! $localVariant) {
                    $localVariant = new ProductVariant();
                    $localVariant->product_id = $product->id;
                    $localVariant->code = $sku !== '' ? $sku : 'SHF-'.$rv['id'];
                    $localVariant->qty = 0;
                    $localVariant->cost = 0;
                }
                $localVariant->name = (string) ($rv['title'] ?? $localVariant->name);
                $localVariant->price = (float) ($rv['price'] ?? 0);
                $localVariant->save();

                foreach (Warehouse::whereNull('deleted_at')->pluck('id') as $warehouseId) {
                    product_warehouse::firstOrCreate([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                        'product_variant_id' => $localVariant->id,
                    ], ['qte' => 0, 'manage_stock' => 1]);
                }

                ShopifyMapping::put($storeId, ShopifyMapping::TYPE_VARIANT, (int) $localVariant->id, (int) $rv['id'], [
                    'product_shopify_id' => $shopifyId,
                    'inventory_item_id' => $rv['inventory_item_id'] ?? null,
                ]);
            }
        }

        return $isNew ? 'created' : 'updated';
    }

    /**
     * products.category_id is NOT NULL — always resolve to a real category,
     * falling back to (or creating) a "Shopify" category.
     */
    private function resolveCategoryId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            $first = Category::first();
            if ($first) {
                return (int) $first->id;
            }
            $name = 'Shopify';
        }

        $category = Category::where('name', $name)->first();
        if (! $category) {
            $category = Category::create(['code' => strtoupper(substr(md5($name), 0, 6)), 'name' => $name]);
        }

        return (int) $category->id;
    }

    private function resolveBrandId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $brand = Brand::where('name', $name)->first();
        if (! $brand) {
            $brand = Brand::create(['name' => $name, 'description' => 'Imported from Shopify']);
        }

        return (int) $brand->id;
    }

    private function defaultUnitId(): ?int
    {
        $unit = DB::table('units')->whereNull('deleted_at')->orderBy('id')->first();

        return $unit ? (int) $unit->id : null;
    }

    // -----------------------------------------------------------------
    // Inventory
    // -----------------------------------------------------------------

    /**
     * Push local stock levels to Shopify (inventory_levels/set) for mapped items.
     */
    public function pushInventory(int $startAfterId = 0, int $batch = 30): array
    {
        $storeId = (int) $this->store->id;
        $locationId = $this->resolveLocationId();
        if (! $locationId) {
            return ['ok' => false, 'error' => 'No Shopify location available. Set a location in the store settings.'];
        }

        $mappings = ShopifyMapping::where('shopify_store_id', $storeId)
            ->whereIn('entity_type', [ShopifyMapping::TYPE_PRODUCT, ShopifyMapping::TYPE_VARIANT])
            ->where('id', '>', $startAfterId)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $updatedCount = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($mappings as $mapping) {
            $lastId = (int) $mapping->id;
            $extra = $mapping->extra ?? [];
            $inventoryItemId = $extra['inventory_item_id'] ?? null;

            if ($mapping->entity_type === ShopifyMapping::TYPE_PRODUCT) {
                $product = Product::find($mapping->local_id);
                if (! $product || (int) $product->is_variant === 1 || ! $inventoryItemId) {
                    $skipped++;
                    continue;
                }
                $qty = $this->localQuantity((int) $product->id, null);
            } else {
                $variant = ProductVariant::find($mapping->local_id);
                if (! $variant || ! $inventoryItemId) {
                    $skipped++;
                    continue;
                }
                $qty = $this->localQuantity((int) $variant->product_id, (int) $variant->id);
            }

            $res = $this->client->post('inventory_levels/set.json', [
                'location_id' => $locationId,
                'inventory_item_id' => (int) $inventoryItemId,
                'available' => (int) round($qty),
            ]);

            if (! $res->successful() && $res->status() === 422) {
                // Item not stocked at this location yet -> connect, then retry
                $this->client->post('inventory_levels/connect.json', [
                    'location_id' => $locationId,
                    'inventory_item_id' => (int) $inventoryItemId,
                ]);
                $res = $this->client->post('inventory_levels/set.json', [
                    'location_id' => $locationId,
                    'inventory_item_id' => (int) $inventoryItemId,
                    'available' => (int) round($qty),
                ]);
            }

            if ($res->successful()) {
                $updatedCount++;
            } else {
                $failed++;
                $errors[] = ['mapping_id' => $mapping->id, 'error' => $res->error()];
            }
        }

        $hasMore = $mappings->count() === $batch;

        $this->log('inventory.push', $failed ? 'warning' : 'info', "Inventory push batch: {$updatedCount} updated, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $mappings->count(),
            'updated' => $updatedCount,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $hasMore,
        ];
    }

    private function localQuantity(int $productId, ?int $variantId): float
    {
        $query = product_warehouse::where('product_id', $productId)
            ->whereNull('deleted_at');

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        if ($this->store->warehouse_id) {
            $query->where('warehouse_id', $this->store->warehouse_id);
        }

        return (float) $query->sum('qte');
    }

    // -----------------------------------------------------------------
    // Customers
    // -----------------------------------------------------------------

    public function pushCustomers(int $startAfterId = 0, int $batch = 25): array
    {
        $storeId = (int) $this->store->id;

        $customers = Customer::whereNull('deleted_at')
            ->where('id', '>', $startAfterId)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $created = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $lastId = $startAfterId;

        foreach ($customers as $customer) {
            $lastId = (int) $customer->id;

            $email = trim((string) $customer->email);
            $phone = trim((string) $customer->phone);
            if ($email === '' && $phone === '') {
                $skipped++; // Shopify requires email or phone
                continue;
            }

            $payload = ['customer' => $this->buildCustomerPayload($customer)];
            $shopifyId = ShopifyMapping::shopifyId($storeId, ShopifyMapping::TYPE_CUSTOMER, (int) $customer->id);

            try {
                if ($shopifyId) {
                    $res = $this->client->put("customers/{$shopifyId}.json", $payload);
                    if ($res->status() === 404) {
                        $shopifyId = null;
                    } elseif ($res->successful()) {
                        $updated++;
                        continue;
                    } else {
                        $failed++;
                        $errors[] = ['client_id' => $customer->id, 'error' => $res->error()];
                        continue;
                    }
                }

                $res = $this->client->post('customers.json', $payload);
                if ($res->successful()) {
                    $remote = $res->json()['customer'] ?? [];
                    if (! empty($remote['id'])) {
                        ShopifyMapping::put($storeId, ShopifyMapping::TYPE_CUSTOMER, (int) $customer->id, (int) $remote['id']);
                    }
                    $created++;
                } elseif ($res->status() === 422 && $email !== '') {
                    // Likely duplicate email -> find remote customer and link
                    $search = $this->client->get('customers/search.json', ['query' => 'email:'.$email]);
                    $match = $search->successful() ? ($search->json()['customers'][0] ?? null) : null;
                    if ($match && ! empty($match['id'])) {
                        ShopifyMapping::put($storeId, ShopifyMapping::TYPE_CUSTOMER, (int) $customer->id, (int) $match['id']);
                        $updated++;
                    } else {
                        $failed++;
                        $errors[] = ['client_id' => $customer->id, 'error' => $res->error()];
                    }
                } else {
                    $failed++;
                    $errors[] = ['client_id' => $customer->id, 'error' => $res->error()];
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['client_id' => $customer->id, 'error' => $e->getMessage()];
            }
        }

        $hasMore = $customers->count() === $batch;

        $this->log('customers.push', $failed ? 'warning' : 'info', "Customers push batch: {$created} created, {$updated} updated, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $customers->count(),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $hasMore,
        ];
    }

    private function buildCustomerPayload(Customer $customer): array
    {
        $firstName = trim((string) ($customer->firstname ?? ''));
        $lastName = trim((string) ($customer->lastname ?? ''));
        if ($firstName === '' && $lastName === '') {
            $parts = preg_split('/\s+/', trim((string) $customer->name), 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';
        }

        $payload = [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
        if (trim((string) $customer->email) !== '') {
            $payload['email'] = trim((string) $customer->email);
        }
        if (trim((string) $customer->phone) !== '') {
            $payload['phone'] = trim((string) $customer->phone);
        }
        if (trim((string) $customer->adresse) !== '' || trim((string) $customer->city) !== '') {
            $payload['addresses'] = [[
                'address1' => (string) $customer->adresse,
                'city' => (string) $customer->city,
                'province' => (string) $customer->state,
                'zip' => (string) $customer->zip,
                'country' => (string) $customer->country,
            ]];
        }

        return $payload;
    }

    public function pullCustomers(?string $pageInfo = null, int $perPage = 50): array
    {
        $query = $pageInfo ? ['limit' => $perPage, 'page_info' => $pageInfo] : ['limit' => $perPage];

        $res = $this->client->get('customers.json', $query);
        if (! $res->successful()) {
            return ['ok' => false, 'error' => $res->error()];
        }

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($res->json()['customers'] ?? [] as $remote) {
            try {
                $result = $this->upsertCustomerFromShopify($remote);
                $result === 'created' ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['shopify_customer_id' => $remote['id'] ?? null, 'error' => $e->getMessage()];
            }
        }

        $next = Client::nextPageInfo($res);

        $this->log('customers.pull', $failed ? 'warning' : 'info', "Customers pull page: {$created} created, {$updated} updated, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'next_page_info' => $next,
            'has_more' => $next !== null,
        ];
    }

    /**
     * Same convention as ClientController::getNumberOrder(). The models here do
     * not use the SoftDeletes trait, so plain queries already include all rows.
     */
    private function nextCustomerCode(): int
    {
        $last = DB::table('clients')->latest('id')->first();

        return $last ? ((int) $last->code + 1) : 1;
    }

    /**
     * @return string 'created' | 'updated'
     */
    public function upsertCustomerFromShopify(array $remote): string
    {
        $storeId = (int) $this->store->id;
        $shopifyId = (int) ($remote['id'] ?? 0);
        $email = trim((string) ($remote['email'] ?? ''));
        $phone = trim((string) ($remote['phone'] ?? ($remote['default_address']['phone'] ?? '')));

        $localId = $shopifyId ? ShopifyMapping::localId($storeId, ShopifyMapping::TYPE_CUSTOMER, $shopifyId) : null;
        $customer = $localId ? Customer::find($localId) : null;

        if (! $customer && $email !== '') {
            $customer = Customer::whereNull('deleted_at')->where('email', $email)->first();
        }
        if (! $customer && $phone !== '') {
            $customer = Customer::whereNull('deleted_at')->where('phone', $phone)->first();
        }

        $firstName = trim((string) ($remote['first_name'] ?? ''));
        $lastName = trim((string) ($remote['last_name'] ?? ''));
        $fullName = trim($firstName.' '.$lastName) ?: ($email !== '' ? $email : 'Shopify customer');
        $address = $remote['default_address'] ?? [];

        $isNew = false;
        if (! $customer) {
            $isNew = true;
            $customer = new Customer();
            $customer->code = $this->nextCustomerCode();
        }

        $customer->name = $fullName;
        $customer->firstname = $firstName ?: null;
        $customer->lastname = $lastName ?: null;
        if ($email !== '') {
            $customer->email = $email;
        }
        if ($phone !== '') {
            $customer->phone = $phone;
        }
        if (! empty($address)) {
            $customer->adresse = (string) ($address['address1'] ?? $customer->adresse);
            $customer->city = (string) ($address['city'] ?? $customer->city);
            $customer->state = (string) ($address['province'] ?? $customer->state);
            $customer->zip = (string) ($address['zip'] ?? $customer->zip);
            $customer->country = (string) ($address['country'] ?? $customer->country);
        }
        $customer->save();

        if ($shopifyId) {
            ShopifyMapping::put($storeId, ShopifyMapping::TYPE_CUSTOMER, (int) $customer->id, $shopifyId);
        }

        return $isNew ? 'created' : 'updated';
    }

    // -----------------------------------------------------------------
    // Orders (pull only: Shopify -> Stocky sales)
    // -----------------------------------------------------------------

    public function pullOrders(?string $pageInfo, int $perPage, int $userId, ?int $warehouseId = null): array
    {
        $query = $pageInfo
            ? ['limit' => $perPage, 'page_info' => $pageInfo]
            : ['limit' => $perPage, 'status' => 'any'];

        $res = $this->client->get('orders.json', $query);
        if (! $res->successful()) {
            return ['ok' => false, 'error' => $res->error()];
        }

        $imported = 0;
        $updatedCount = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($res->json()['orders'] ?? [] as $order) {
            try {
                $result = $this->importOrderPayload($order, $userId, $warehouseId);
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'updated') {
                    $updatedCount++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['shopify_order_id' => $order['id'] ?? null, 'error' => $e->getMessage()];
            }
        }

        $next = Client::nextPageInfo($res);

        $this->log('orders.pull', $failed ? 'warning' : 'info', "Orders pull page: {$imported} imported, {$updatedCount} updated, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'imported' => $imported,
            'updated' => $updatedCount,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'next_page_info' => $next,
            'has_more' => $next !== null,
        ];
    }

    /**
     * Create (or refresh statuses of) a Stocky sale from a Shopify order payload.
     * Used by both manual pull and the orders/create|updated webhooks.
     *
     * @return string 'imported' | 'updated' | 'skipped'
     */
    public function importOrderPayload(array $order, int $userId, ?int $warehouseId = null): string
    {
        $storeId = (int) $this->store->id;
        $shopifyOrderId = (int) ($order['id'] ?? 0);
        if (! $shopifyOrderId) {
            return 'skipped';
        }

        $existingSaleId = ShopifyMapping::localId($storeId, ShopifyMapping::TYPE_ORDER, $shopifyOrderId);
        if ($existingSaleId) {
            $sale = Sale::find($existingSaleId);
            if ($sale) {
                $sale->payment_statut = $this->mapFinancialStatus((string) ($order['financial_status'] ?? ''));
                $sale->statut = $this->mapFulfillmentStatus($order['fulfillment_status'] ?? null);
                if ($sale->payment_statut === 'paid') {
                    $sale->paid_amount = (float) ($order['total_price'] ?? $sale->GrandTotal);
                }
                $sale->save();
            }

            return 'updated';
        }

        // Never create a sale for an order that was already cancelled on Shopify
        if (! empty($order['cancelled_at'])) {
            return 'skipped';
        }

        $warehouseId = $warehouseId
            ?: ($this->store->warehouse_id ?: (int) Warehouse::whereNull('deleted_at')->orderBy('id')->value('id'));
        if (! $warehouseId) {
            throw new \RuntimeException('No warehouse available to receive the order.');
        }

        // Customer
        $clientId = null;
        if (! empty($order['customer'])) {
            $this->upsertCustomerFromShopify($order['customer']);
            $clientId = ShopifyMapping::localId($storeId, ShopifyMapping::TYPE_CUSTOMER, (int) $order['customer']['id']);
        }
        if (! $clientId) {
            $clientId = (int) Customer::whereNull('deleted_at')->orderBy('id')->value('id');
            if (! $clientId) {
                $walkIn = new Customer();
                $walkIn->name = 'Shopify customer';
                $walkIn->code = $this->nextCustomerCode();
                $walkIn->save();
                $clientId = (int) $walkIn->id;
            }
        }

        // Resolve line items to local products/variants
        $resolved = [];
        $unresolved = [];
        foreach ($order['line_items'] ?? [] as $item) {
            $mapped = $this->resolveLineItem($item);
            if ($mapped) {
                $resolved[] = ['item' => $item] + $mapped;
            } else {
                $unresolved[] = ['sku' => $item['sku'] ?? null, 'title' => $item['title'] ?? null];
            }
        }

        if (empty($resolved)) {
            $this->log('orders.import', 'warning', 'Order skipped: no line item matched a local product', [
                'shopify_order_id' => $shopifyOrderId,
                'order_number' => $order['order_number'] ?? null,
                'unresolved' => $unresolved,
            ]);

            return 'skipped';
        }

        $createdAt = ! empty($order['created_at']) ? \Carbon\Carbon::parse($order['created_at']) : now();
        $shippingTotal = 0.0;
        foreach ($order['shipping_lines'] ?? [] as $line) {
            $shippingTotal += (float) ($line['price'] ?? 0);
        }

        $paymentStatut = $this->mapFinancialStatus((string) ($order['financial_status'] ?? ''));
        $grandTotal = (float) ($order['total_price'] ?? 0);

        $sale = null;
        DB::transaction(function () use ($order, $resolved, $unresolved, $clientId, $userId, $warehouseId, $createdAt, $shippingTotal, $paymentStatut, $grandTotal, $storeId, $shopifyOrderId, &$sale) {
            $sale = Sale::create([
                'date' => $createdAt->format('Y-m-d'),
                'time' => $createdAt->format('H:i:s'),
                // Store id in the Ref keeps references unique across multiple Shopify stores
                'Ref' => 'SHF'.$storeId.'-'.($order['order_number'] ?? $shopifyOrderId),
                'is_pos' => 0,
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'user_id' => $userId,
                'tax_rate' => 0,
                'TaxNet' => (float) ($order['total_tax'] ?? 0),
                'discount' => (float) ($order['total_discounts'] ?? 0),
                'shipping' => $shippingTotal,
                'GrandTotal' => $grandTotal,
                'paid_amount' => $paymentStatut === 'paid' ? $grandTotal : 0,
                'payment_statut' => $paymentStatut,
                'statut' => $this->mapFulfillmentStatus($order['fulfillment_status'] ?? null),
                'notes' => 'Imported from Shopify ('.$this->store->shop_domain.') — order '
                    .($order['name'] ?? ('#'.($order['order_number'] ?? $shopifyOrderId)))
                    .(empty($unresolved) ? '' : ' — unmatched items: '.json_encode($unresolved, JSON_UNESCAPED_UNICODE)),
            ]);

            foreach ($resolved as $row) {
                $item = $row['item'];
                $qty = (float) ($item['quantity'] ?? 1);
                $price = (float) ($item['price'] ?? 0);

                SaleDetail::create([
                    'date' => $createdAt->format('Y-m-d'),
                    'sale_id' => $sale->id,
                    'product_id' => $row['product_id'],
                    'product_variant_id' => $row['variant_id'],
                    'quantity' => $qty,
                    'price' => $price,
                    'TaxNet' => 0,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_method' => '2',
                    'total' => round($price * $qty, 2),
                ]);

                // Decrement stock in the receiving warehouse
                $stockQuery = product_warehouse::where('product_id', $row['product_id'])
                    ->where('warehouse_id', $warehouseId)
                    ->whereNull('deleted_at');
                if ($row['variant_id']) {
                    $stockQuery->where('product_variant_id', $row['variant_id']);
                } else {
                    $stockQuery->whereNull('product_variant_id');
                }
                $stock = $stockQuery->first();
                if ($stock) {
                    $stock->qte = (float) $stock->qte - $qty;
                    $stock->save();
                }
            }

            ShopifyMapping::put($storeId, ShopifyMapping::TYPE_ORDER, (int) $sale->id, $shopifyOrderId, [
                'order_number' => $order['order_number'] ?? null,
                'order_name' => $order['name'] ?? null,
            ]);
        }, 3);

        return 'imported';
    }

    /**
     * @return array{product_id:int, variant_id:?int}|null
     */
    private function resolveLineItem(array $item): ?array
    {
        $storeId = (int) $this->store->id;

        // 1) Variant mapping
        if (! empty($item['variant_id'])) {
            $localVariantId = ShopifyMapping::localId($storeId, ShopifyMapping::TYPE_VARIANT, (int) $item['variant_id']);
            if ($localVariantId) {
                $variant = ProductVariant::find($localVariantId);
                if ($variant) {
                    return ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id];
                }
            }
        }

        // 2) Product mapping (simple products store their variant on the product mapping)
        if (! empty($item['product_id'])) {
            $localProductId = ShopifyMapping::localId($storeId, ShopifyMapping::TYPE_PRODUCT, (int) $item['product_id']);
            if ($localProductId) {
                $product = Product::find($localProductId);
                if ($product && (int) $product->is_variant !== 1) {
                    return ['product_id' => (int) $product->id, 'variant_id' => null];
                }
            }
        }

        // 3) SKU fallback
        $sku = trim((string) ($item['sku'] ?? ''));
        if ($sku !== '') {
            $variant = ProductVariant::whereNull('deleted_at')->where('code', $sku)->first();
            if ($variant) {
                return ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id];
            }
            $product = Product::whereNull('deleted_at')->where('code', $sku)->first();
            if ($product) {
                return ['product_id' => (int) $product->id, 'variant_id' => null];
            }
        }

        return null;
    }

    private function mapFinancialStatus(string $status): string
    {
        return match ($status) {
            'paid', 'refunded', 'partially_refunded' => 'paid',
            'partially_paid' => 'partial',
            default => 'unpaid',
        };
    }

    private function mapFulfillmentStatus(?string $status): string
    {
        // Valid Stocky sale statuses: completed | pending | ordered
        return match ($status) {
            'fulfilled' => 'completed',
            'partial' => 'pending',
            default => 'ordered',
        };
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    public function handleWebhook(string $topic, array $payload): void
    {
        switch ($topic) {
            case 'orders/create':
            case 'orders/updated':
                $userId = (int) (DB::table('users')->orderBy('id')->value('id') ?: 1);
                $result = $this->importOrderPayload($payload, $userId, null);
                $this->log('webhook.'.$topic, 'info', 'Order webhook processed: '.$result, [
                    'shopify_order_id' => $payload['id'] ?? null,
                ]);
                break;

            case 'customers/create':
            case 'customers/update':
                $result = $this->upsertCustomerFromShopify($payload);
                $this->log('webhook.'.$topic, 'info', 'Customer webhook processed: '.$result, [
                    'shopify_customer_id' => $payload['id'] ?? null,
                ]);
                break;

            case 'products/update':
                $this->applyRemoteProductUpdate($payload);
                break;

            case 'products/delete':
                ShopifyMapping::where('shopify_store_id', $this->store->id)
                    ->where('entity_type', ShopifyMapping::TYPE_PRODUCT)
                    ->where('shopify_id', (int) ($payload['id'] ?? 0))
                    ->delete();
                $this->log('webhook.products/delete', 'info', 'Product mapping removed', [
                    'shopify_product_id' => $payload['id'] ?? null,
                ]);
                break;

            case 'app/uninstalled':
                $this->store->is_active = false;
                $this->store->save();
                $this->log('webhook.app/uninstalled', 'warning', 'App uninstalled on Shopify; store deactivated');
                break;

            default:
                $this->log('webhook.'.$topic, 'info', 'Unhandled webhook topic received');
        }
    }

    /**
     * Only update local fields for products that are already mapped — never create
     * from a products/update webhook (avoids echo loops after a push).
     */
    private function applyRemoteProductUpdate(array $payload): void
    {
        $storeId = (int) $this->store->id;
        $shopifyId = (int) ($payload['id'] ?? 0);
        $localId = $shopifyId ? ShopifyMapping::localId($storeId, ShopifyMapping::TYPE_PRODUCT, $shopifyId) : null;
        if (! $localId) {
            return;
        }

        $this->importRemoteProduct($payload);
        $this->log('webhook.products/update', 'info', 'Mapped product refreshed from Shopify', [
            'shopify_product_id' => $shopifyId, 'product_id' => $localId,
        ]);
    }

    // -----------------------------------------------------------------
    // Stats / reset
    // -----------------------------------------------------------------

    public function stats(): array
    {
        $storeId = (int) $this->store->id;
        $count = fn (string $type) => ShopifyMapping::where('shopify_store_id', $storeId)
            ->where('entity_type', $type)->count();

        return [
            'products_total' => Product::whereNull('deleted_at')->count(),
            'products_mapped' => $count(ShopifyMapping::TYPE_PRODUCT),
            'customers_total' => Customer::whereNull('deleted_at')->count(),
            'customers_mapped' => $count(ShopifyMapping::TYPE_CUSTOMER),
            'orders_imported' => $count(ShopifyMapping::TYPE_ORDER),
            'variants_mapped' => $count(ShopifyMapping::TYPE_VARIANT),
        ];
    }

    public function resetMappings(?string $entityType = null): int
    {
        $query = ShopifyMapping::where('shopify_store_id', $this->store->id);
        if ($entityType) {
            $query->where('entity_type', $entityType);
        } else {
            // Keep order mappings by default: they guard against duplicate sale imports
            $query->where('entity_type', '!=', ShopifyMapping::TYPE_ORDER);
        }

        return $query->delete();
    }

    // -----------------------------------------------------------------

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            ShopifyLog::create([
                'shopify_store_id' => $this->store->id,
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a sync
        }
    }
}
