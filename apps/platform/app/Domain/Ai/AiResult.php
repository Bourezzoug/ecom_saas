<?php

namespace App\Domain\Ai;

/**
 * Outcome of one model call, as returned by a driver.
 */
final readonly class AiResult
{
    /**
     * @param  array<string, mixed>|null  $data  Decoded JSON (generateJson / generateFromImage); null for text.
     * @param  string|null  $text  Text output (generateText); null for JSON calls.
     * @param  string  $raw  The untouched model output.
     * @param  int  $costCredits  Provider-metered cost. 0 for local models; user-facing action prices live in config/credits.php.
     */
    public function __construct(
        public ?array $data,
        public ?string $text,
        public string $raw,
        public string $provider,
        public string $model,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $durationMs = 0,
        public int $costCredits = 0,
    ) {}

    /**
     * Usage fields in the shape of the generations table.
     *
     * @return array{provider: string, model: string, tokens_in: int, tokens_out: int, duration_ms: int, raw_output: string}
     */
    public function toUsage(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'duration_ms' => $this->durationMs,
            'raw_output' => $this->raw,
        ];
    }
}
