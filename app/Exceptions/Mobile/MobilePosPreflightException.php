<?php

namespace App\Exceptions\Mobile;

use RuntimeException;

class MobilePosPreflightException extends RuntimeException
{
    public function __construct(
        private string $errorCode,
        private int $statusCode = 422,
        private array $details = [],
        string $message = ''
    ) {
        parent::__construct($message ?: $errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function details(): array
    {
        return $this->details;
    }
}
