<?php

namespace App\Domain\Ai\Exceptions;

/**
 * The model's answer was still invalid after the repair attempt (or the call failed).
 */
class GenerationFailedException extends AiException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [], ?string $raw = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $raw, $previous);
    }
}
