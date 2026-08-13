<?php

namespace App\Http\Controllers;

use App\Models\ShopifyLog;
use App\Models\ShopifyStore;
use App\Services\Shopify\SyncService;
use Illuminate\Http\Request;

/**
 * Public receiver for Shopify webhooks.
 *
 * Every request is authenticated by its X-Shopify-Hmac-Sha256 signature,
 * verified against the store's app API secret key — no session/token auth.
 */
class ShopifyWebhookController extends Controller
{
    public function handle(Request $request, $storeId)
    {
        $store = ShopifyStore::find($storeId);
        if (! $store) {
            return response()->json(['ok' => false], 404);
        }

        $rawBody = $request->getContent();
        if (! $this->verifySignature($store, $rawBody, (string) $request->header('X-Shopify-Hmac-Sha256', ''))) {
            try {
                ShopifyLog::create([
                    'shopify_store_id' => $store->id,
                    'action' => 'webhook.rejected',
                    'level' => 'warning',
                    'message' => 'Webhook rejected: invalid HMAC signature',
                    'context' => ['topic' => $request->header('X-Shopify-Topic')],
                ]);
            } catch (\Throwable $e) {
            }

            return response()->json(['ok' => false, 'error' => 'Invalid signature'], 401);
        }

        // Ignore webhooks from a different shop than the one configured
        $shopHeader = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));
        if ($shopHeader !== '' && $shopHeader !== strtolower($store->normalizedDomain())) {
            return response()->json(['ok' => false, 'error' => 'Shop mismatch'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'Invalid payload'], 400);
        }

        try {
            SyncService::forStore($store)->handleWebhook($topic, $payload);
        } catch (\Throwable $e) {
            try {
                ShopifyLog::create([
                    'shopify_store_id' => $store->id,
                    'action' => 'webhook.error',
                    'level' => 'error',
                    'message' => $e->getMessage(),
                    'context' => ['topic' => $topic, 'payload_id' => $payload['id'] ?? null],
                ]);
            } catch (\Throwable $inner) {
            }
            // Return 200 anyway so Shopify does not retry a payload that will keep failing
        }

        return response()->json(['ok' => true]);
    }

    private function verifySignature(ShopifyStore $store, string $rawBody, string $hmacHeader): bool
    {
        $secret = (string) $store->api_secret;
        if ($secret === '' || $hmacHeader === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($computed, $hmacHeader);
    }
}
