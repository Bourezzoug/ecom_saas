<?php

namespace Aisg\Connector\Import;

use RuntimeException;

/**
 * A page was edited in WordPress since the last publish; not overwritten.
 */
final class ConflictException extends RuntimeException {}
