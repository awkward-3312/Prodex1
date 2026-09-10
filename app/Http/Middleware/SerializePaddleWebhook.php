<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Serialize concurrent deliveries of the same Paddle event before the
 * controller reaches its database idempotency checks.
 */
class SerializePaddleWebhook
{
    public function handle(Request $request, Closure $next)
    {
        $rawBody = $request->getContent();
        $decoded = json_decode($rawBody, true);
        $eventId = is_array($decoded) ? trim((string) ($decoded['event_id'] ?? '')) : '';

        // Invalid payloads still get a deterministic lock and are subsequently
        // rejected by the controller. Never use untrusted event IDs directly as
        // cache keys.
        $identity = $eventId !== '' ? $eventId : hash('sha256', $rawBody);
        $lock = Cache::lock('paddle:webhook:'.hash('sha256', $identity), 30);

        try {
            return $lock->block(10, fn () => $next($request));
        } catch (LockTimeoutException) {
            // Non-2xx asks Paddle to retry instead of letting a concurrent
            // duplicate race through fulfillment side effects.
            return response('Webhook is already being processed.', 409);
        }
    }
}
