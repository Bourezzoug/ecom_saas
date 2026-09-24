<?php

namespace App\Domain\Publishing\Exceptions;

use RuntimeException;
use Throwable;

class ConnectorException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(string $message, public readonly int $status, public readonly array $body = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public function isConflict(): bool
    {
        return $this->status === 409;
    }
}
