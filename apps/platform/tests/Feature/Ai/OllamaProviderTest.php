<?php

use App\Domain\Ai\AiManager;
use App\Domain\Ai\Capability;
use App\Domain\Ai\Drivers\OllamaProvider;
use App\Domain\Ai\Exceptions\AiConnectionException;
use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Exceptions\AiResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'ai.driver' => 'ollama',
        'ai.drivers.ollama.host' => 'http://ollama.test:11434',
        'ai.drivers.ollama.retries' => 1,
        'ai.drivers.ollama.retry_sleep_ms' => 0,
        'ai.models' => ['planner' => 'planner-model', 'copy' => 'copy-model', 'vision' => 'vision-model'],
    ]);
});

function ollama(): OllamaProvider
{
    return app(AiManager::class)->driver('ollama');
}

function chatResponse(string $content, array $extra = []): array
{
    return [
        'model' => 'copy-model',
        'message' => ['role' => 'assistant', 'content' => $content],
        'done' => true,
        'prompt_eval_count' => 42,
        'eval_count' => 17,
        'total_duration' => 1_500_000_000,
        ...$extra,
    ];
}

$schema = [
    'type' => 'object',
    'properties' => ['headline' => ['type' => 'string']],
    'required' => ['headline'],
];

test('generateJson sends the schema as Ollama structured-output format', function () use ($schema) {
    Http::fake(['ollama.test:11434/api/chat' => Http::response(chatResponse('{"headline":"Hello"}'))]);

    $result = ollama()->generateJson('system prompt', 'user prompt', $schema, ['task' => 'planner', 'seed' => 7]);

    expect($result->data)->toBe(['headline' => 'Hello'])
        ->and($result->raw)->toBe('{"headline":"Hello"}')
        ->and($result->provider)->toBe('ollama')
        ->and($result->tokensIn)->toBe(42)
        ->and($result->tokensOut)->toBe(17)
        ->and($result->durationMs)->toBe(1500)
        ->and($result->costCredits)->toBe(0);

    Http::assertSent(function (Request $request) use ($schema) {
        return $request->url() === 'http://ollama.test:11434/api/chat'
            && $request['model'] === 'planner-model'
            && $request['format'] === $schema
            && $request['stream'] === false
            && $request['think'] === false
            && $request['options']['seed'] === 7
            && $request['messages'][0] === ['role' => 'system', 'content' => 'system prompt']
            && $request['messages'][1] === ['role' => 'user', 'content' => 'user prompt'];
    });
});

test('the copy model is used when no task is given and an explicit model wins', function () use ($schema) {
    Http::fake(['*' => Http::response(chatResponse('{"headline":"x"}'))]);

    ollama()->generateJson('s', 'p', $schema);
    ollama()->generateJson('s', 'p', $schema, ['task' => 'planner', 'model' => 'explicit']);

    $models = Http::recorded()->map(fn ($pair) => $pair[0]['model'])->all();

    expect($models)->toBe(['copy-model', 'explicit']);
});

test('prior messages are inserted between system and user turns (repair pass)', function () use ($schema) {
    Http::fake(['*' => Http::response(chatResponse('{"headline":"fixed"}'))]);

    $previous = [
        ['role' => 'user', 'content' => 'first try'],
        ['role' => 'assistant', 'content' => '{"bad":true}'],
    ];

    ollama()->generateJson('s', 'fix it', $schema, ['messages' => $previous]);

    Http::assertSent(fn (Request $request) => array_column($request['messages'], 'role') === ['system', 'user', 'assistant', 'user']);
});

test('a code-fenced JSON answer is still decoded', function () use ($schema) {
    Http::fake(['*' => Http::response(chatResponse("```json\n{\"headline\":\"Fenced\"}\n```"))]);

    expect(ollama()->generateJson('s', 'p', $schema)->data)->toBe(['headline' => 'Fenced']);
});

test('non-JSON output raises a response exception that keeps the raw output', function () use ($schema) {
    Http::fake(['*' => Http::response(chatResponse('Sure! Here is your headline: Hello'))]);

    try {
        ollama()->generateJson('s', 'p', $schema);
        $this->fail('Expected AiResponseException');
    } catch (AiResponseException $e) {
        expect($e->raw)->toBe('Sure! Here is your headline: Hello');
    }
});

test('a JSON list is rejected because every schema root is an object', function () use ($schema) {
    Http::fake(['*' => Http::response(chatResponse('[1,2,3]'))]);

    ollama()->generateJson('s', 'p', $schema);
})->throws(AiResponseException::class);

test('an empty message raises a response exception', function () use ($schema) {
    Http::fake(['*' => Http::response(chatResponse('   '))]);

    ollama()->generateJson('s', 'p', $schema);
})->throws(AiResponseException::class, 'empty message');

test('a missing model gives an actionable error', function () use ($schema) {
    Http::fake(['*' => Http::response(['error' => "model 'copy-model' not found"], 404)]);

    ollama()->generateJson('s', 'p', $schema);
})->throws(AiResponseException::class, 'ollama pull copy-model');

test('server errors are retried, then surface as connection exceptions', function () use ($schema) {
    Http::fake(['*' => Http::response(['error' => 'out of memory'], 500)]);

    expect(fn () => ollama()->generateJson('s', 'p', $schema))
        ->toThrow(AiConnectionException::class, 'out of memory');

    Http::assertSentCount(2); // 1 try + 1 retry
});

test('a transient server error followed by success returns the result', function () use ($schema) {
    Http::fakeSequence()
        ->push(['error' => 'loading model'], 503)
        ->push(chatResponse('{"headline":"ok"}'));

    expect(ollama()->generateJson('s', 'p', $schema)->data)->toBe(['headline' => 'ok']);
});

test('an unreachable host raises a connection exception', function () use ($schema) {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    ollama()->generateJson('s', 'p', $schema);
})->throws(AiConnectionException::class, 'Could not reach Ollama');

test('generateText returns trimmed text and no data', function () {
    Http::fake(['*' => Http::response(chatResponse("  A lovely mug.\n"))]);

    $result = ollama()->generateText('s', 'describe a mug', ['max_tokens' => 50]);

    expect($result->text)->toBe('A lovely mug.')
        ->and($result->data)->toBeNull();

    Http::assertSent(fn (Request $request) => ! isset($request['format']) && $request['options']['num_predict'] === 50);
});

test('generateFromImage base64-encodes the image and uses the vision model', function () use ($schema) {
    Http::fake(['*' => Http::response(chatResponse('{"headline":"From image"}'))]);
    $image = base_path('tests/Fixtures/images/pixel.png');

    $result = ollama()->generateFromImage('s', 'map this design', $image, $schema);

    expect($result->data)->toBe(['headline' => 'From image']);

    Http::assertSent(fn (Request $request) => $request['model'] === 'vision-model'
        && $request['messages'][1]['images'] === [base64_encode(file_get_contents($image))]
        && $request['format'] === $schema);
});

test('generateFromImage rejects a missing file before calling the model', function () use ($schema) {
    Http::fake();

    expect(fn () => ollama()->generateFromImage('s', 'p', '/nope.png', $schema))->toThrow(AiException::class, 'does not exist');

    Http::assertNothingSent();
});

test('vision support depends on a configured vision model', function () {
    expect(ollama()->supports(Capability::Vision))->toBeTrue();

    config(['ai.models.vision' => null]);
    app()->forgetInstance(AiManager::class);

    expect(ollama()->supports(Capability::Vision))->toBeFalse()
        ->and(ollama()->supports(Capability::Json))->toBeTrue();
});

test('ai:ping prints the structured result', function () {
    Http::fake(['*' => Http::response(chatResponse('{"store_name":"Clay","tagline":"Mugs","tone":"friendly"}'))]);

    $this->artisan('ai:ping')
        ->expectsOutputToContain('"store_name": "Clay"')
        ->assertSuccessful();
});

test('ai:ping fails cleanly when the model is missing', function () {
    Http::fake(['*' => Http::response(['error' => 'model not found'], 404)]);

    $this->artisan('ai:ping')
        ->expectsOutputToContain('ollama pull copy-model')
        ->assertFailed();
});

test('timeouts are not retried (a looping model would burn the job budget)', function () use ($schema) {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('cURL error 28: Operation timed out after 90001 milliseconds');
    });

    expect(fn () => ollama()->generateJson('s', 'p', $schema, ['timeout' => 90]))
        ->toThrow(AiConnectionException::class);

    expect($attempts)->toBe(1);
});

test('refused connections are retried', function () use ($schema) {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('cURL error 7: Failed to connect to ollama.test port 11434: Connection refused');
    });

    expect(fn () => ollama()->generateJson('s', 'p', $schema))->toThrow(AiConnectionException::class);

    expect($attempts)->toBe(2);
});

test('per-call timeout and output cap reach Ollama', function () use ($schema) {
    Http::fake(['*' => Http::response(chatResponse('{"headline":"ok"}'))]);

    ollama()->generateJson('s', 'p', $schema, ['max_tokens' => 800]);

    Http::assertSent(fn (Request $request) => $request['options']['num_predict'] === 800);
});
