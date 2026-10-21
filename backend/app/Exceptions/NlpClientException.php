<?php

namespace App\Exceptions;

use RuntimeException;

class NlpClientException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}
