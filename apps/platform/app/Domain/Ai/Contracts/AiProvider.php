<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\AiResult;
use App\Domain\Ai\Capability;
use App\Domain\Ai\Exceptions\AiException;

/**
 * A model backend (Ollama, Fake, later Anthropic/OpenAI).
 *
 * Drivers only talk to the model: they do not validate against the schema,
 * repair, sanitize or log. That is the StructuredGenerator's job (M2), so every
 * driver behaves the same for business code.
 *
 * Common $options keys:
 *  - model:       explicit model name (overrides routing)
 *  - task:        model route: "planner" | "copy" | "vision" (config/ai.php)
 *  - temperature: float
 *  - max_tokens:  int
 *  - seed:        int, for reproducible output where the backend supports it
 *  - timeout:     seconds
 *  - messages:    extra prior turns [{role, content}] (used by the repair pass)
 */
interface AiProvider
{
    /**
     * The driver name, e.g. "ollama".
     */
    public function name(): string;

    /**
     * Whether this driver (and its configured models) supports the capability.
     */
    public function supports(Capability $capability): bool;

    /**
     * Generate a JSON object constrained to $jsonSchema. AiResult::$data holds
     * the decoded object; it is NOT yet validated against the schema.
     *
     * @param  array<string, mixed>  $jsonSchema
     * @param  array<string, mixed>  $options
     *
     * @throws AiException
     */
    public function generateJson(string $system, string $prompt, array $jsonSchema, array $options = []): AiResult;

    /**
     * Generate free text.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws AiException
     */
    public function generateText(string $system, string $prompt, array $options = []): AiResult;

    /**
     * Generate a JSON object from an image (design import).
     *
     * @param  array<string, mixed>  $jsonSchema
     * @param  array<string, mixed>  $options
     *
     * @throws AiException
     */
    public function generateFromImage(string $system, string $prompt, string $imagePath, array $jsonSchema, array $options = []): AiResult;
}
