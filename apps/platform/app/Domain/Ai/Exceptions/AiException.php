<?php

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

/**
 * Base class for every AI failure. Jobs catch this to fail gracefully and refund credits.
 */
class AiException extends RuntimeException
{
    /**
     * @param  string|null  $raw  Raw model output, when there was one (logged to generations.raw_output).
     */
    public function __construct(string $message, public readonly ?string $raw = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
