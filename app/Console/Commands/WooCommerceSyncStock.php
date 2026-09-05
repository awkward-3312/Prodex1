<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\WooCommerceLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WooCommerceSyncStock extends Command
{
    protected $signature = 'woocommerce:sync-stock {--batch=200 : Number of products per chunk}';

    protected $description = 'Sync POS stock quantities and stock_status to WooCommerce for products with woocommerce_id';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('services.woocommerce.url'), '/');
        $key = (string) config('services.woocommerce.consumer_key');
        $secret = (string) config('services.woocommerce.consumer_secret');

        if ($baseUrl === '' || $key === '' || $secret === '') {
            $this->error('WooCommerce credentials are not configured.');

            return self::FAILURE;
        }

        $endpoint = $baseUrl.'/wp-json/wc/v3/products/';
        $batchSize = max(1, (int) $this->option('batch'));

        $total = 0;
        $success = 0;
        $failed = 0;
        DB::connection()->disableQueryLog();

        $query = Product::query()
            ->whereNull('deleted_at')
            ->whereNotNull('woocommerce_id')
            ->orderBy('id');

        $this->info('Starting WooCommerce stock sync...');
        $query->chunkById($batchSize, function ($products) use (&$total, &$success, &$failed, $endpoint, $key, $secret) {
            foreach ($products as $product) {
                $total++;
                $stockResult = $this->computeStockQuantity($product);

                if ($stockResult['blocked']) {
                    // MS7-B2-2C — FAIL CLOSED: no remote PUT with a fake
                    // zero fallback when the native read couldn't be
                    // resolved safely.
                    $failed++;
                    WooCommerceLog::create([
                        'action' => 'stock.sync',
                        'level' => 'warning',
                        'message' => 'Stock push blocked: '.$stockResult['blocked_reason'],
                        'context' => [
                            'product_id' => $product->id,
                            'woocommerce_id' => (int) $product->woocommerce_id,
                        ],
                    ]);
                    $this->line(json_encode(['progress' => ['product_id' => $product->id, 'ok' => false, 'blocked' => $stockResult['blocked_reason']]]));

                    continue;
                }

                $qty = (int) round($stockResult['quantity']);
                $status = $qty > 0 ? 'instock' : 'outofstock';

                $payload = [
                    'manage_stock' => true,
                    'stock_quantity' => $qty,
                    'stock_status' => $status,
                ];

                try {
                    $res = Http::timeout(45)
                        ->retry(2, 500)
                        ->withBasicAuth($key, $secret)
                        ->put($endpoint.(int) $product->woocommerce_id, $payload);

                    if ($res->successful()) {
                        $success++;
                        WooCommerceLog::create([
                            'action' => 'stock.sync',
                            'level' => 'info',
                            'message' => 'Stock synced',
                            'context' => [
                                'product_id' => $product->id,
                                'product_name' => $product->name,
                                'woocommerce_id' => (int) $product->woocommerce_id,
                                'qty' => $qty,
                                'stock_status' => $status,
                            ],
                        ]);
                        $this->line(json_encode(['progress' => ['product_id' => $product->id, 'ok' => true]]));
                    } else {
                        $failed++;
                        WooCommerceLog::create([
                            'action' => 'stock.sync',
                            'level' => 'error',
                            'message' => 'Woo request failed',
                            'context' => [
                                'product_id' => $product->id,
                                'woocommerce_id' => (int) $product->woocommerce_id,
                                'status' => $res->status(),
                                'body' => substr($res->body(), 0, 500),
                                'payload' => $payload,
                            ],
                        ]);
                        $this->line(json_encode(['progress' => ['product_id' => $product->id, 'ok' => false]]));
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    WooCommerceLog::create([
                        'action' => 'stock.sync',
                        'level' => 'error',
                        'message' => $e->getMessage(),
                        'context' => [
                            'product_id' => $product->id,
                            'woocommerce_id' => (int) $product->woocommerce_id,
                        ],
                    ]);
                    $this->line(json_encode(['progress' => ['product_id' => $product->id, 'ok' => false]]));
                }
            }
        });

        $summary = ['processed' => $total, 'success' => $success, 'failed' => $failed];
        WooCommerceLog::create([
            'action' => 'stock.sync',
            'level' => 'info',
            'message' => 'Stock sync finished',
            'context' => $summary,
        ]);
        $this->info('Done: '.json_encode($summary));

        return self::SUCCESS;
    }

    /**
     * MS7-B2-2C.1 — this command is simple-only (a pre-existing, documented
     * limitation this milestone does not expand); it now reuses the SAME
     * single-canonical-warehouse calculator WooCommerceStockSyncJob uses
     * (never an aggregate across every warehouse in the tenant) instead of
     * a raw product_warehouse sum.
     *
     * @return array{quantity: float, blocked: bool, blocked_reason: ?string}
     */
    private function computeStockQuantity(Product $product): array
    {
        $isBatch = (bool) ($product->is_batch_tracked ?? false);
        $isImei = (int) ($product->is_imei ?? 0) === 1;

        return app(\App\Services\ExternalChannelInventoryService::class)
            ->sellableQuantityForFulfillmentWarehouse((int) $product->id, null, $isBatch, $isImei);
    }
}
