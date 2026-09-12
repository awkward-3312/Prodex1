<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Central\TenantSubscription;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serializes concurrent billing actions (cancel/resume) for the same
 * subscription — a customer click, an admin action, and an incoming Paddle
 * webhook can all race to read-modify-write the same row while a live
 * Paddle API call is in flight. One shared lock namespace
 * ('billing:cancel:{subscription id}') across every writer is what actually
 * closes that race; a DB transaction alone would hold a row lock for as
 * long as the outbound Paddle call takes, which is worse than the race it
 * prevents.
 */
trait LocksBillingSubscription
{
    private function withSubscriptionLock(TenantSubscription $subscription, Closure $action): mixed
    {
        $lock = Cache::lock('billing:cancel:'.$subscription->id, 40);

        try {
            return $lock->block(5, $action);
        } catch (LockTimeoutException) {
            return $this->onSubscriptionLockTimeout();
        }
    }

    /**
     * What to return when the lock could not be acquired in time. Each
     * consumer's own return type differs (JsonResponse, RedirectResponse, a
     * thrown exception for the webhook path), so this is the one thing they
     * still implement themselves.
     */
    abstract private function onSubscriptionLockTimeout(): mixed;
}
