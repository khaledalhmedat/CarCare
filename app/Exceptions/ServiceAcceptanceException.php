<?php

namespace App\Exceptions;

/**
 * Thrown when a provider/technician tries to accept a request that fails a
 * pre-acceptance business rule (out of service range, unsupported fuel type,
 * missing coordinates, ...). Carries a machine-readable code + structured data
 * so controllers can return a clean JSON error instead of a raw exception message.
 */
class ServiceAcceptanceException extends \Exception
{
    private string $errorCode;
    private array $context;
    private int $httpStatus;

    public function __construct(string $message, string $errorCode, array $context = [], int $httpStatus = 422)
    {
        parent::__construct($message);

        $this->errorCode = $errorCode;
        $this->context = $context;
        $this->httpStatus = $httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
