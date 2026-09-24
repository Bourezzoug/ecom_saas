<?php

namespace App\Domain\Ai;

use Closure;

/**
 * One structured AI task: what to ask, the schema the model fills, and how the
 * answer is cleaned and checked before it is accepted.
 */
final readonly class StructuredRequest
{
    /**
     * @param  string  $route  Model route in config/ai.php ("planner", "copy", "vision").
     * @param  array<string, mixed>  $schema  JSON Schema sent to the model (simple keywords only).
     * @param  Closure(array<string, mixed>): array<string, mixed>  $prepare  Sanitises/normalises decoded output.
     * @param  Closure(array<string, mixed>): list<string>  $validate  Returns errors for the prepared output (empty = valid).
     * @param  array<string, mixed>  $options  Extra driver options (temperature, max_tokens...).
     */
    public function __construct(
        public string $route,
        public string $system,
        public string $prompt,
        public array $schema,
        public Closure $prepare,
        public Closure $validate,
        public array $options = [],
        public ?string $imagePath = null,
    ) {}
}
