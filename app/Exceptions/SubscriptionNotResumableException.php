<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a subscription cannot be resumed because it was already
 * confirmed cancelled at Paddle — Paddle has no "un-cancel" operation for a
 * terminated subscription, so this must never be papered over by silently
 * reactivating local access with no counterpart at Paddle.
 */
class SubscriptionNotResumableException extends RuntimeException
{
}
