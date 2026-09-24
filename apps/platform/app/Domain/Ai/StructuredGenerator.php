<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Exceptions\AiResponseException;
use App\Domain\Ai\Exceptions\GenerationFailedException;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Runs a StructuredRequest with the reliability rules of spec §3.2:
 *
 *   call model → decode → prepare (sanitise) → validate
 *     └ invalid → ONE repair call (previous answer + errors) → prepare → validate
 *         └ still invalid → GenerationFailedException
 *
 * Every attempt is recorded on the given generations row (prompt, schema, raw
 * output, errors, tokens, duration, status). Drivers stay unaware of all this.
 */
class StructuredGenerator
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly Config $config,
    ) {}

    /**
     * @return array<string, mixed> The prepared, validated output.
     *
     * @throws GenerationFailedException
     */
    public function run(Generation $generation, StructuredRequest $request): array
    {
        $provider = $this->ai->provider();
        $maxRepairs = (int) $this->config->get('ai.repair_attempts', 1);
        $options = ['task' => $request->route, ...$request->options];

        $generation->fill([
            'status' => GenerationStatus::Running,
            'provider' => $provider->name(),
            'model' => $this->ai->modelFor($request->route),
            'system_prompt' => $request->system,
            'prompt' => $request->prompt,
            'json_schema' => $request->schema,
            'started_at' => now(),
        ])->save();

        $messages = [];
        $prompt = $request->prompt;
        $usage = ['tokens_in' => 0, 'tokens_out' => 0, 'duration_ms' => 0];
        $errors = [];
        $raw = null;

        for ($attempt = 1; $attempt <= 1 + $maxRepairs; $attempt++) {
            try {
                $result = $request->imagePath !== null && $attempt === 1
                    ? $provider->generateFromImage($request->system, $prompt, $request->imagePath, $request->schema, $options)
                    : $provider->generateJson($request->system, $prompt, $request->schema, [...$options, 'messages' => $messages]);
            } catch (AiResponseException $e) {
                // Unusable answer (not JSON): repairable like a validation error.
                $raw = $e->raw;
                $errors = ['The answer was not a valid JSON object: '.$e->getMessage()];
                [$messages, $prompt] = $this->repairTurn($request->prompt, $messages, $prompt, $raw ?? '', $errors);

                continue;
            } catch (AiException $e) {
                // Transport failure (already retried by the driver): not repairable.
                $this->fail($generation, $attempt, $usage, $raw, [$e->getMessage()], $e->getMessage());

                throw new GenerationFailedException($e->getMessage(), [], $raw, $e);
            } finally {
                if (isset($result)) {
                    $usage['tokens_in'] += $result->tokensIn;
                    $usage['tokens_out'] += $result->tokensOut;
                    $usage['duration_ms'] += $result->durationMs;
                    $generation->model = $result->model;
                }
            }

            $raw = $result->raw;
            $prepared = ($request->prepare)($result->data ?? []);
            $errors = ($request->validate)($prepared);

            if ($errors === []) {
                $generation->fill([
                    ...$usage,
                    'status' => $attempt === 1 ? GenerationStatus::Succeeded : GenerationStatus::Repaired,
                    'attempts' => $attempt,
                    'output' => $prepared,
                    'raw_output' => $raw,
                    'validation_errors' => null,
                    'finished_at' => now(),
                ])->save();

                return $prepared;
            }

            [$messages, $prompt] = $this->repairTurn($request->prompt, $messages, $prompt, $raw, $errors);
            unset($result);
        }

        $this->fail($generation, 1 + $maxRepairs, $usage, $raw, $errors, 'Output still invalid after repair.');

        throw new GenerationFailedException('The AI output did not match the schema after a repair attempt.', $errors, $raw);
    }

    /**
     * Build the conversation for the repair call: the original request, the
     * model's answer, and a short list of what to fix.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<string>  $errors
     * @return array{0: list<array{role: string, content: string}>, 1: string}
     */
    private function repairTurn(string $originalPrompt, array $messages, string $lastPrompt, string $raw, array $errors): array
    {
        $messages = [
            ...$messages,
            ['role' => 'user', 'content' => $lastPrompt],
            ['role' => 'assistant', 'content' => $raw],
        ];

        $prompt = "Your JSON has these problems:\n- ".implode("\n- ", array_slice($errors, 0, 10))
            ."\n\nReturn the complete corrected JSON object only. Keep everything that was already correct.";

        return [$messages, $prompt];
    }

    /**
     * @param  array{tokens_in: int, tokens_out: int, duration_ms: int}  $usage
     * @param  list<string>  $errors
     */
    private function fail(Generation $generation, int $attempts, array $usage, ?string $raw, array $errors, string $message): void
    {
        $generation->fill([
            ...$usage,
            'status' => GenerationStatus::Failed,
            'attempts' => $attempts,
            'raw_output' => $raw,
            'validation_errors' => $errors,
            'error' => $message,
            'finished_at' => now(),
        ])->save();
    }
}
