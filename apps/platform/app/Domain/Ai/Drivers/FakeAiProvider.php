<?php

namespace App\Domain\Ai\Drivers;

use App\Domain\Ai\AiResult;
use App\Domain\Ai\Capability;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Exceptions\AiResponseException;
use App\Domain\Ai\Exceptions\UnsupportedCapabilityException;
use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * Deterministic provider for tests and offline development.
 *
 * Response resolution order for each call:
 *  1. the next scripted response pushed with push() (array, string, Throwable or Closure)
 *  2. the responder set with respondUsing() (return null to fall through)
 *  3. a fixture file {fixtures}/{options.fixture}.json
 *  4. data synthesised from the JSON schema (defaults, first enum value, minItems...)
 */
class FakeAiProvider implements AiProvider
{
    /** @var list<array<string, mixed>|string|Throwable|Closure> */
    protected array $queue = [];

    /** @var list<array{method: string, system: string, prompt: string, schema: array<string, mixed>|null, image: string|null, options: array<string, mixed>}> */
    protected array $calls = [];

    private ?Closure $responder = null;

    /** @var array<string, bool> */
    protected array $capabilities = ['json' => true, 'text' => true, 'vision' => true];

    public function __construct(protected ?string $fixturesPath = null) {}

    public function name(): string
    {
        return 'fake';
    }

    public function supports(Capability $capability): bool
    {
        return $this->capabilities[$capability->value];
    }

    public function withoutCapability(Capability $capability): static
    {
        $this->capabilities[$capability->value] = false;

        return $this;
    }

    /**
     * Queue responses consumed in order by the next calls.
     *
     * An array is returned as decoded JSON, a string as raw output (useful for
     * invalid JSON), a Throwable is thrown, a Closure receives the call record.
     *
     * @param  array<string, mixed>|string|Throwable|Closure  ...$responses
     */
    public function push(array|string|Throwable|Closure ...$responses): static
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    /**
     * Answer every call that has no scripted response, e.g. by inspecting the
     * schema or options. Return null to fall through to fixtures/synthesis.
     *
     * @param  Closure(array<string, mixed>): (array<string, mixed>|string|Throwable|null)  $responder
     */
    public function respondUsing(Closure $responder): static
    {
        $this->responder = $responder;

        return $this;
    }

    public function generateJson(string $system, string $prompt, array $jsonSchema, array $options = []): AiResult
    {
        return $this->respondJson('generateJson', $system, $prompt, $jsonSchema, null, $options);
    }

    public function generateText(string $system, string $prompt, array $options = []): AiResult
    {
        $call = $this->record('generateText', $system, $prompt, null, null, $options);
        $response = $this->nextResponse($call) ?? 'Fake response.';
        $text = is_array($response) ? (string) json_encode($response) : $response;

        return $this->result(null, $text, $text, $options);
    }

    public function generateFromImage(string $system, string $prompt, string $imagePath, array $jsonSchema, array $options = []): AiResult
    {
        if (! $this->supports(Capability::Vision)) {
            throw UnsupportedCapabilityException::for($this->name(), Capability::Vision);
        }

        return $this->respondJson('generateFromImage', $system, $prompt, $jsonSchema, $imagePath, ['task' => 'vision', ...$options]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $options
     */
    protected function respondJson(string $method, string $system, string $prompt, array $schema, ?string $image, array $options): AiResult
    {
        $call = $this->record($method, $system, $prompt, $schema, $image, $options);

        $response = $this->nextResponse($call)
            ?? $this->fixture($options['fixture'] ?? null)
            ?? $this->exampleFor($schema);

        if (is_string($response)) {
            $data = json_decode($response, true);

            if (! is_array($data)) {
                throw new AiResponseException('Model output is not a JSON object: '.json_last_error_msg(), $response);
            }

            return $this->result($data, null, $response, $options);
        }

        return $this->result($response, null, (string) json_encode($response), $options);
    }

    /**
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>|string|null
     */
    protected function nextResponse(array $call): array|string|null
    {
        if ($this->queue !== []) {
            $response = array_shift($this->queue);
        } elseif ($this->responder !== null) {
            $response = ($this->responder)($call);
        } else {
            return null;
        }

        if ($response instanceof Closure) {
            $response = $response($call);
        }

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fixture(?string $name): ?array
    {
        if ($name === null || $this->fixturesPath === null) {
            return null;
        }

        $path = rtrim($this->fixturesPath, '/').'/'.$name.'.json';

        if (! is_file($path)) {
            throw new \InvalidArgumentException("AI fixture [{$name}] not found at [{$path}].");
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Build a minimal deterministic value that satisfies common JSON Schema keywords.
     *
     * @param  array<string, mixed>  $schema
     */
    public function exampleFor(array $schema, string $path = 'value'): mixed
    {
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        if (isset($schema['const'])) {
            return $schema['const'];
        }

        if (! empty($schema['enum'])) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string');
        $type = is_array($type) ? $type[0] : $type;

        return match ($type) {
            'object' => $this->exampleObject($schema, $path),
            'array' => array_map(
                fn (int $i) => $this->exampleFor($schema['items'] ?? [], "{$path}_{$i}"),
                range(1, max(1, (int) ($schema['minItems'] ?? 1))),
            ),
            'integer' => (int) ($schema['minimum'] ?? 1),
            'number' => (float) ($schema['minimum'] ?? 1),
            'boolean' => true,
            'null' => null,
            default => $this->exampleString($schema, $path),
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function exampleObject(array $schema, string $path): array
    {
        $object = [];

        foreach ($schema['properties'] ?? [] as $key => $property) {
            $object[$key] = $this->exampleFor($property, $key);
        }

        return $object;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function exampleString(array $schema, string $path): string
    {
        $value = 'Sample '.str_replace('_', ' ', $path);
        $min = (int) ($schema['minLength'] ?? 0);
        $max = (int) ($schema['maxLength'] ?? 0);

        if (strlen($value) < $min) {
            $value = str_pad($value, $min, '.');
        }

        return $max > 0 ? substr($value, 0, $max) : $value;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>  $options
     */
    protected function result(?array $data, ?string $text, string $raw, array $options): AiResult
    {
        return new AiResult(
            data: $data,
            text: $text,
            raw: $raw,
            provider: $this->name(),
            model: $options['model'] ?? 'fake-'.($options['task'] ?? 'copy'),
            tokensIn: 10,
            tokensOut: 20,
            durationMs: 1,
        );
    }

    /**
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $options
     * @return array{method: string, system: string, prompt: string, schema: array<string, mixed>|null, image: string|null, options: array<string, mixed>}
     */
    protected function record(string $method, string $system, string $prompt, ?array $schema, ?string $image, array $options): array
    {
        return $this->calls[] = compact('method', 'system', 'prompt', 'schema', 'image', 'options');
    }

    /**
     * @return list<array{method: string, system: string, prompt: string, schema: array<string, mixed>|null, image: string|null, options: array<string, mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function assertCallCount(int $expected): void
    {
        PHPUnit::assertCount($expected, $this->calls, "Expected {$expected} AI calls, got ".count($this->calls).'.');
    }

    public function assertNothingCalled(): void
    {
        $this->assertCallCount(0);
    }

    /**
     * @param  Closure(array<string, mixed>): bool  $callback
     */
    public function assertCalled(Closure $callback): void
    {
        PHPUnit::assertTrue(
            collect($this->calls)->contains($callback),
            'The expected AI call was not made.',
        );
    }
}
