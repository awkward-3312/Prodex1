<?php

namespace App\Exceptions\Mobile;

use RuntimeException;

class MobileProductResolveException extends RuntimeException
{
    public function __construct(
        protected string $errorCode,
        protected int $statusCode
    ) {
        parent::__construct($errorCode);
    }

    public static function productNotFound(): self
    {
        return new self('product_not_found', 404);
    }

    public static function ambiguousCode(): self
    {
        return new self('ambiguous_code', 409);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
