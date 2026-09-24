<?php

namespace App\Domain\Ai\Exceptions;

use App\Domain\Ai\Capability;

class UnsupportedCapabilityException extends AiException
{
    public static function for(string $driver, Capability $capability): self
    {
        return new self("AI driver [{$driver}] does not support [{$capability->value}].");
    }
}
