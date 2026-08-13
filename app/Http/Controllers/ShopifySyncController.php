<?php

namespace App\Http\Controllers;

use App\Models\ShopifyLog;
use App\Models\ShopifyMapping;
use App\Models\ShopifyStore;
use App\Models\Warehouse;
use App\Services\Shopify\SyncService;
use Illuminate\Http\Request;

class ShopifySyncController extends BaseController
{
    // -----------------------------------------------------------------
    // Stores (multi-store CRUD)
    // -----------------------------------------------------------------

    public function stores(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        return response()->json([
            'stores' => ShopifyStore::orderBy('id')->get(),
            'warehouses' => Warehouse::whereNull('deleted_at')->get(['id', 'name']),
        ]);
    }

    public function createStore(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', ShopifyStore::class);

        $data = $this->validateStore($request);

        $store = ShopifyStore::create($data);

        return response()->json(['success' => true, 'store' => $store]);
    }

    public function updateStore(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $store = ShopifyStore::findOrFail($id);
        $data = $this->validateStore($request, (int) $id);

        // Pointing the record to a different shop invalidates all previous mappings
        $domainChanged = $this->normalizeDomain($data['shop_domain']) !== $store->normalizedDomain();

        $store->fill($data);
        $store->save();

        if ($domainChanged) {
            ShopifyMapping::where('shopify_store_id', $store->id)->delete();
            $store->location_id = null;
            $store->location_name = null;
            $store->last_sync_at = null;
            $store->save();

            SyncService::forStore($store)->log('store.update', 'warning', 'Shop domain changed — all mappings were reset');
        }

        return response()->json(['success' => true, 'store' => $store]);
    }

    public function deleteStore(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ShopifyStore::class);

        $store = ShopifyStore::findOrFail($id);
        ShopifyMapping::where('shopify_store_id', $store->id)->delete();
        ShopifyLog::where('shopify_store_id', $store->id)->delete();
        $store->delete();

        return response()->json(['success' => true]);
    }

    private function validateStore(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:shopify_stores,shop_domain'.($ignoreId ? ','.$ignoreId : '');

        $data = $request->validate([
            'name' => 'required|string|max:191',
            'shop_domain' => 'required|string|max:191|'.$unique,
            'access_token' => 'required|string',
            'api_secret' => 'nullable|string',
            'api_version' => 'nullable|string|max:20',
            'location_id' => 'nullable|integer',
            'location_name' => 'nullable|string|max:191',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'is_active' => 'nullable|boolean',
            'enable_auto_sync' => 'nullable|boolean',
            'sync_interval' => 'nullable|string|max:32',
        ]);

        $data['shop_domain'] = $this->normalizeDomain($data['shop_domain']);
        $data['api_version'] = $data['api_version'] ?? '2025-01';
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['enable_auto_sync'] = (bool) ($data['enable_auto_sync'] ?? false);

        return $data;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#i', '', trim($domain));

        return rtrim(explode('/', $domain)[0] ?? '', '/');
    }

    private function resolveStore(Request $request, $id = null): ShopifyStore
    {
        $storeId = $id ?? $request->input('store_id', $request->query('store_id'));

        if ($storeId) {
            return ShopifyStore::findOrFail($storeId);
        }

        $store = ShopifyStore::orderBy('id')->first();
        abort_if(! $store, 422, 'No Shopify store configured');

        return $store;
    }

    // -----------------------------------------------------------------
    // Connection / metadata
    // -----------------------------------------------------------------

    public function testConnection(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $store = ShopifyStore::findOrFail($id);
        if (empty($store->shop_domain) || empty($store->access_token)) {
            return response()->json(['ok' => false, 'error' => 'Missing Shopify credentials'], 422);
        }

        $service = SyncService::forStore($store);
        $result = $service->testConnection();
        if (empty($result['ok'])) {
            $service->log('connect.test', 'error', 'Connection test failed', [
                'status' => $result['status'] ?? null,
                'error' => $result['error'] ?? null,
            ]);
        }

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    public function locations(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $store = ShopifyStore::findOrFail($id);
        $result = SyncService::forStore($store)->fetchLocations();

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    public function registerWebhooks(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $store = ShopifyStore::findOrFail($id);
        if (empty($store->api_secret)) {
            return response()->json([
                'ok' => false,
                'error' => 'Set the app API secret key first — it is required to verify webhook signatures.',
            ], 422);
        }

        $callback = $request->getSchemeAndHttpHost().'/api/shopify/webhook/'.$store->id;
        $result = SyncService::forStore($store)->registerWebhooks($callback);
        $result['callback'] = $callback;

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    public function stats(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $store = ShopifyStore::findOrFail($id);

        return response()->json(['ok' => true, 'stats' => SyncService::forStore($store)->stats()]);
    }

    public function resetMappings(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $store = ShopifyStore::findOrFail($id);
        $entityType = $request->input('entity_type');
        if ($entityType !== null) {
            $request->validate(['entity_type' => 'in:product,variant,customer,order']);
        }

        $service = SyncService::forStore($store);
        $deleted = $service->resetMappings($entityType);
        if ($entityType === 'product') {
            // Variants are meaningless without their parent product mapping
            $deleted += $service->resetMappings('variant');
        }
        $service->log('mappings.reset', 'warning', "Mappings reset ({$deleted} removed)", ['entity_type' => $entityType]);

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    // -----------------------------------------------------------------
    // Sync endpoints — cursor-based batches, the UI loops while has_more
    // -----------------------------------------------------------------

    public function syncProducts(Request $request)
    {
        $this->prepareLongRequest();
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $store = $this->resolveStore($request);
        $service = SyncService::forStore($store);

        if ((string) $request->query('mode', 'push') === 'pull') {
            $result = $service->pullProducts(
                $request->input('page_info') ?: null,
                (int) $request->input('per_page', 50)
            );
        } else {
            $result = $service->pushProducts(
                (bool) $request->boolean('only_unsynced', false),
                (int) $request->input('start_after_id', 0),
                (int) $request->input('batch', 15)
            );
        }

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    public function syncInventory(Request $request)
    {
        $this->prepareLongRequest();
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $store = $this->resolveStore($request);
        $result = SyncService::forStore($store)->pushInventory(
            (int) $request->input('start_after_id', 0),
            (int) $request->input('batch', 30)
        );

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    public function syncCustomers(Request $request)
    {
        $this->prepareLongRequest();
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $store = $this->resolveStore($request);
        $service = SyncService::forStore($store);

        if ((string) $request->query('mode', 'push') === 'pull') {
            $result = $service->pullCustomers(
                $request->input('page_info') ?: null,
                (int) $request->input('per_page', 50)
            );
        } else {
            $result = $service->pushCustomers(
                (int) $request->input('start_after_id', 0),
                (int) $request->input('batch', 25)
            );
        }

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    public function syncOrders(Request $request)
    {
        $this->prepareLongRequest();
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $store = $this->resolveStore($request);
        $result = SyncService::forStore($store)->pullOrders(
            $request->input('page_info') ?: null,
            (int) $request->input('per_page', 25),
            (int) optional($request->user('api'))->id,
            $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null
        );

        return response()->json($result, ! empty($result['ok']) ? 200 : 422);
    }

    private function prepareLongRequest(): void
    {
        @ini_set('max_execution_time', '600');
        @ini_set('memory_limit', '512M');
    }

    // -----------------------------------------------------------------
    // Logs
    // -----------------------------------------------------------------

    public function logs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $query = ShopifyLog::orderByDesc('id');
        if ($request->filled('store_id')) {
            $query->where('shopify_store_id', (int) $request->input('store_id'));
        }
        if ($request->filled('level')) {
            $query->where('level', (string) $request->input('level'));
        }

        return response()->json($query->paginate((int) $request->input('per_page', 25)));
    }

    public function clearLogs(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ShopifyStore::class);

        $query = ShopifyLog::query();
        if ($request->filled('store_id')) {
            $query->where('shopify_store_id', (int) $request->input('store_id'));
        }
        $deleted = $query->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
