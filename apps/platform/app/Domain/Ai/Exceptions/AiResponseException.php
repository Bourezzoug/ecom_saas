<?php

namespace App\Domain\Ai\Exceptions;

/**
 * The backend answered, but the answer is unusable (not JSON, empty, error payload).
 */
class AiResponseException extends AiException {}
