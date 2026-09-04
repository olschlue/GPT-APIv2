<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Exception, die direkt als JSON-Fehlerantwort mit HTTP-Status ausgegeben wird.
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
