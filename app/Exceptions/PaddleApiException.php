<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a call to Paddle's REST API fails (non-2xx response, or the
 * request could not be sent). Never carries the API key or full response
 * body — only what is safe to log.
 */
class PaddleApiException extends RuntimeException
{
}
