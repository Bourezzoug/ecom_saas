<?php

namespace Aisg\Sections\Exceptions;

use RuntimeException;

class InvalidSectionException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public static function withErrors(string $key, array $errors): self
    {
        return new self("Section [{$key}] is invalid:\n - ".implode("\n - ", $errors));
    }
}
