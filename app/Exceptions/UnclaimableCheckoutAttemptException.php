<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Paddle webhook references a checkout attempt that already
 * expired (or was otherwise never left in a claimable state). Distinct from
 * a generic processing failure so PaddleWebhookController::handle() can tell
 * it apart and acknowledge the event (200) instead of asking Paddle to
 * retry an event that can never succeed.
 */
class UnclaimableCheckoutAttemptException extends RuntimeException
{
}
