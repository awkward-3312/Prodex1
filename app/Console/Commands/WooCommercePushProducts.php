<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WooCommercePushProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:push-products {--batch=100 : Number of products to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push all existing POS products to WooCommerce if they do not have woocommerce_id. Links by SKU to avoid duplicates.';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('services.woocommerce.url'), '/');
        $key = (string) config('services.woocommerce.consumer_key');
        $secret = (string) config('services.woocommerce.consumer_secret');

        if (empty($baseUrl) || empty($key) || empty($secret)) {
            $this->error('WooCommerce credentials are not configured. Please set WOOCOMMERCE_URL, WOOCOMMERCE_CONSUMER_KEY, WOOCOMMERCE_CONSUMER_SECRET in .env and config/services.php.');

            return self::FAILURE;
        }

        $endpointProducts = $baseUrl.'/wp-json/wc/v3/products';
        $batchSize = max(1, (int) $this->option('batch'));

        $totalProcessed = 0;
        $linkedCount = 0;
        $createdCount = 0;
        $errorCount = 0;

        DB::connection()->disableQueryLog();

        $query = Product::query()->whereNull('woocommerce_id')->orderBy('id');

        $this->info('Starting WooCommerce push for products without woocommerce_id...');

        $query->chunkById($batchSize, function ($products) use (&$totalProcessed, &$linkedCount, &$createdCount, &$errorCount, $endpointProducts, $key, $secret) {
            foreach ($products as $product) {
                try {
                    $totalProcessed++;
                    $sku = (string) ($product->code ?? '');
                    if ($sku === '') {
                        $this->warn("Skipping product ID {$product->id}: missing SKU/code.");

                        continue;
                    }

                    // 1) Pre-check by SKU to avoid duplicates
                    $existing = Http::timeout(30)
                        ->retry(2, 500)
                        ->withBasicAuth($key, $secret)
                        ->get($endpointProducts, ['sku' => $sku]);

                    if ($existing->successful()) {
                        $list = $existing->json();
                        if (is_array($list) && count($list) > 0 && isset($list[0]['id'])) {
                            $wcId = (int) $list[0]['id'];
                            $this->info("Linking product ID {$product->id} to existing WooCommerce ID {$wcId} (SKU {$sku}).");
                            $product->timestamps = false; // do not modify timestamps
                            $product->woocommerce_id = $wcId;
                            $product->save();
                            $linkedCount++;

                            continue; // skip creation
                        }
                    } else {
                        $this->warn("SKU pre-check failed for product ID {$product->id} (SKU {$sku}). Status: {$existing->status()} Body: ".substr($existing->body(), 0, 500));
                    }

                    // 2) Build payload for creation
                    $payload = $this->buildProductPayload($product);

                    $response = Http::timeout(60)
                        ->retry(2, 500)
                        ->withBasicAuth($key, $secret)
                        ->post($endpointProducts, $payload);

                    if ($response->successful() || $response->status() === 201) {
                        $data = $response->json();
                        $wcId = (int) ($data['id'] ?? 0);
                        if ($wcId > 0) {
                            $product->timestamps = false; // do not modify timestamps
                            $product->woocommerce_id = $wcId;
                            $product->save();
                            $createdCount++;
                            $this->info("Created WooCommerce product for ID {$product->id} (SKU {$sku}) => WC ID {$wcId}.");
                        } else {
                            $errorCount++;
                            $this->error("Unexpected response for product ID {$product->id} (SKU {$sku}). Body: ".substr($response->body(), 0, 500));
                        }
                    } else {
                        $errorCount++;
                        $this->error("Failed to create product ID {$product->id} (SKU {$sku}). Status: {$response->status()} Body: ".substr($response->body(), 0, 500));
                    }
                } catch (\Throwable $e) {
                    $errorCount++;
                    $this->error("Error processing product ID {$product->id}: {$e->getMessage()}");
                }
            }
        });

        $this->line('');
        $this->info("Finished. Processed: {$totalProcessed}, Linked: {$linkedCount}, Created: {$createdCount}, Errors: {$errorCount}.");

        return self::SUCCESS;
    }

    private function buildProductPayload(Product $product): array
    {
        $sku = (string) ($product->code ?? '');
        $price = $this->formatPrice($product->price ?? 0);
        $stockResult = $this->computeStockQuantity($product);

        $payload = [
            'name' => (string) ($product->name ?? $sku),
            'sku' => $sku,
            'regular_price' => $price,
            'status' => 'publish',
        ];

        // MS7-B2-2C — FAIL CLOSED, never a stale/fake stock number: if the
        // native read couldn't be resolved safely (missing fulfillment
        // location, or a batch/serial coverage mismatch), create the
        // product WITHOUT any managed-stock fields rather than publish a
        // wrong quantity. A later WooCommerceStockSyncJob run picks it up
        // once the underlying issue is fixed.
        if (! $stockResult['blocked']) {
            $payload['manage_stock'] = true;
            $payload['stock_quantity'] = (int) round($stockResult['quantity']);
        } else {
            $this->warn("Product ID {$product->id} (SKU {$sku}): stock not published — {$stockResult['blocked_reason']}.");
        }

        // Optional image sync
        $imageName = (string) ($product->image ?? '');
        if ($imageName !== '') {
            $publicPath = upload_public_path('products').'/'.$imageName;
            if (is_file($publicPath)) {
                $payload['images'] = [
                    ['src' => global_asset(upload_path('products').'/'.$imageName)],
                ];
            }
        }

        return $payload;
    }

    /**
     * MS7-B2-2C — this command is simple-only (no variant awareness, a
     * pre-existing, documented limitation this milestone does not
     * expand); it now reuses the SAME native-aware canonical calculator
     * WooCommerceStockSyncJob uses instead of a raw product_warehouse sum.
     *
     * @return array{quantity: float, blocked: bool, blocked_reason: ?string}
     */
    private function computeStockQuantity(Product $product): array
    {
        $isBatch = (bool) ($product->is_batch_tracked ?? false);
        $isImei = (int) ($product->is_imei ?? 0) === 1;

        return app(\App\Services\ExternalChannelInventoryService::class)
            ->sellableQuantityAcrossWarehouses((int) $product->id, null, $isBatch, $isImei);
    }

    private function formatPrice($value): string
    {
        $num = is_numeric($value) ? (float) $value : 0.0;

        return number_format($num, 2, '.', '');
    }
}
