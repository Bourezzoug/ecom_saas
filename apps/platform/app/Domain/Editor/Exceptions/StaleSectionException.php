<?php

namespace App\Domain\Editor\Exceptions;

use App\Models\PageSection;
use RuntimeException;

/**
 * The section changed since the client loaded it (another tab, a teammate, or an AI rewrite).
 */
class StaleSectionException extends RuntimeException
{
    public function __construct(public readonly ?PageSection $current)
    {
        parent::__construct('This section was changed elsewhere.');
    }
}
