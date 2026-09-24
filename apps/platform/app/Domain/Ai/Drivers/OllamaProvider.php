<?php

namespace App\Domain\Ai\Drivers;

use App\Domain\Ai\AiResult;
use App\Domain\Ai\Capability;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Exceptions\AiConnectionException;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Exceptions\AiResponseException;
use App\Domain\Ai\Exceptions\UnsupportedCapabilityException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Local models through Ollama's /api/chat, using structured outputs
 * (`format` = JSON Schema) for every JSON call.
 *
 * @see https://github.com/ollama/ollama/blob/main/docs/api.md#generate-a-chat-completion
 */
class OllamaProvider implements AiProvider
{
    /**
     * @param  array{host: string, timeout?: int, connect_timeout?: int, keep_alive?: string, retries?: int, retry_sleep_ms?: int, options?: array<string, mixed>, think?: bool}  $config
     * @param  array<string, string|null>  $models  Model routes (planner, copy, vision).
     */
    public function __construct(
        protected HttpFactory $http,
        protected array $config,
        protected array $models,
    ) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Json, Capability::Text => true,
            Capability::Vision => filled($this->models['vision'] ?? null),
        };
    }

    public function generateJson(string $system, string $prompt, array $jsonSchema, array $options = []): AiResult
    {
        return $this->chatForJson($system, ['role' => 'user', 'content' => $prompt], $jsonSchema, $options);
    }

    public function generateText(string $system, string $prompt, array $options = []): AiResult
    {
        [$body, $model, $durationMs] = $this->chat($system, ['role' => 'user', 'content' => $prompt], null, $options);
        $content = $this->content($body);

        return new AiResult(
            data: null,
            text: trim($content),
            raw: $content,
            provider: $this->name(),
            model: $model,
            tokensIn: (int) ($body['prompt_eval_count'] ?? 0),
            tokensOut: (int) ($body['eval_count'] ?? 0),
            durationMs: $durationMs,
        );
    }

    public function generateFromImage(string $system, string $prompt, string $imagePath, array $jsonSchema, array $options = []): AiResult
    {
        if (! $this->supports(Capability::Vision)) {
            throw UnsupportedCapabilityException::for($this->name(), Capability::Vision);
        }

        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new AiException("Image [{$imagePath}] does not exist or is not readable.");
        }

        $message = [
            'role' => 'user',
            'content' => $prompt,
            'images' => [base64_encode((string) file_get_contents($imagePath))],
        ];

        return $this->chatForJson($system, $message, $jsonSchema, ['task' => 'vision', ...$options]);
    }

    /**
     * Run a chat call constrained to a JSON schema and decode the answer.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $jsonSchema
     * @param  array<string, mixed>  $options
     */
    protected function chatForJson(string $system, array $message, array $jsonSchema, array $options): AiResult
    {
        [$body, $model, $durationMs] = $this->chat($system, $message, $jsonSchema, $options);
        $content = $this->content($body);

        return new AiResult(
            data: $this->decodeObject($content),
            text: null,
            raw: $content,
            provider: $this->name(),
            model: $model,
            tokensIn: (int) ($body['prompt_eval_count'] ?? 0),
            tokensOut: (int) ($body['eval_count'] ?? 0),
            durationMs: $durationMs,
        );
    }

    /**
     * POST /api/chat and return [decoded body, model, duration in ms].
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|null  $format
     * @param  array<string, mixed>  $options
     * @return array{0: array<string, mixed>, 1: string, 2: int}
     */
    protected function chat(string $system, array $message, ?array $format, array $options): array
    {
        $model = $this->resolveModel($options);

        $payload = array_filter([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ...($options['messages'] ?? []),
                $message,
            ],
            'stream' => false,
            'format' => $format,
            'think' => $this->config['think'] ?? false,
            'keep_alive' => $this->config['keep_alive'] ?? null,
            'options' => $this->modelOptions($options),
        ], fn ($value) => $value !== null);

        $started = hrtime(true);

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->config['host'], '/'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout($this->config['connect_timeout'] ?? 5)
                ->timeout($options['timeout'] ?? $this->config['timeout'] ?? 180)
                ->retry(
                    times: max(1, ($this->config['retries'] ?? 0) + 1),
                    sleepMilliseconds: $this->config['retry_sleep_ms'] ?? 500,
                    // Retry refused connections and 5xx, never timeouts: a timed-out model is
                    // slow or looping, and retrying would just burn the job's time budget.
                    when: fn (Throwable $e) => ($e instanceof ConnectionException && ! self::isTimeout($e))
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->post('/api/chat', $payload);
        } catch (ConnectionException $e) {
            throw new AiConnectionException("Could not reach Ollama at [{$this->config['host']}]: {$e->getMessage()}", previous: $e);
        }

        $this->ensureSuccessful($response, $model);

        $body = $response->json();

        if (! is_array($body)) {
            throw new AiResponseException('Ollama returned a non-JSON response body.', $response->body());
        }

        $durationMs = isset($body['total_duration'])
            ? intdiv((int) $body['total_duration'], 1_000_000)
            : intdiv(hrtime(true) - $started, 1_000_000);

        return [$body, (string) ($body['model'] ?? $model), $durationMs];
    }

    protected static function isTimeout(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;
    }

    protected function ensureSuccessful(Response $response, string $model): void
    {
        if ($response->successful()) {
            return;
        }

        $error = $response->json('error') ?? $response->body();

        if ($response->serverError()) {
            throw new AiConnectionException("Ollama server error ({$response->status()}): {$error}");
        }

        if ($response->status() === 404) {
            throw new AiResponseException("Ollama model [{$model}] is not available. Pull it with `ollama pull {$model}`. ({$error})");
        }

        throw new AiResponseException("Ollama rejected the request ({$response->status()}): {$error}");
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function resolveModel(array $options): string
    {
        $model = $options['model'] ?? $this->models[$options['task'] ?? 'copy'] ?? null;

        if (blank($model)) {
            throw new AiException('No model configured for task ['.($options['task'] ?? 'copy').']. Set AI_MODEL_* in .env.');
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function modelOptions(array $options): array
    {
        return array_filter([
            ...($this->config['options'] ?? []),
            'temperature' => $options['temperature'] ?? $this->config['options']['temperature'] ?? null,
            'num_predict' => $options['max_tokens'] ?? null,
            'seed' => $options['seed'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function content(array $body): string
    {
        $content = $body['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new AiResponseException('Ollama returned an empty message.', json_encode($body) ?: null);
        }

        return $content;
    }

    /**
     * Decode a JSON object, tolerating a Markdown code fence around it.
     *
     * @return array<string, mixed>
     */
    protected function decodeObject(string $content): array
    {
        $json = trim($content);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $matches)) {
            $json = $matches[1];
        }

        $data = json_decode($json, true);

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw new AiResponseException('Model output is not a JSON object: '.json_last_error_msg(), $content);
        }

        return $data;
    }
}
