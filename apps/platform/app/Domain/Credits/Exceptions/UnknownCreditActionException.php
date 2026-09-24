<?php

namespace App\Domain\Credits\Exceptions;

use InvalidArgumentException;

class UnknownCreditActionException extends InvalidArgumentException
{
    public static function for(string $action): self
    {
        return new self("No credit cost is configured for action [{$action}] (config/credits.php).");
    }
}
