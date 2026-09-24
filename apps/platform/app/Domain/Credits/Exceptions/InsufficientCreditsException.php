<?php

namespace App\Domain\Credits\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $available)
    {
        parent::__construct("This action needs {$required} credits but only {$available} are available.");
    }
}
