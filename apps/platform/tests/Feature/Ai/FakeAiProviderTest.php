<?php

use App\Domain\Ai\AiManager;
use App\Domain\Ai\Capability;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Drivers\FakeAiProvider;
use App\Domain\Ai\Drivers\OllamaProvider;
use App\Domain\Ai\Exceptions\AiConnectionException;
use App\Domain\Ai\Exceptions\AiResponseException;
use App\Domain\Ai\Exceptions\UnsupportedCapabilityException;

test('the manager resolves the configured default driver', function () {
    config(['ai.driver' => 'ollama']);
    expect(app(AiManager::class)->driver())->toBeInstanceOf(OllamaProvider::class);

    config(['ai.driver' => 'fake']);
    app()->forgetInstance(AiManager::class);
    expect(app(AiProvider::class))->toBeInstanceOf(FakeAiProvider::class);
});

test('the test suite runs on the fake driver', function () {
    expect(config('ai.driver'))->toBe('fake')
        ->and(app(AiProvider::class)->name())->toBe('fake');
});

test('fake() swaps the container binding', function () {
    $fake = app(AiManager::class)->fake();

    expect(app(AiProvider::class))->toBe($fake)
        ->and(app(AiManager::class)->driver())->toBe($fake);
});

test('without scripted responses it synthesises data from the schema', function () {
    $fake = new FakeAiProvider;

    $result = $fake->generateJson('s', 'p', [
        'type' => 'object',
        'properties' => [
            'headline' => ['type' => 'string', 'maxLength' => 12],
            'tone' => ['type' => 'string', 'enum' => ['warm', 'cold']],
            'items' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'minimum' => 3]]]],
            'cta' => ['type' => 'string', 'default' => 'Shop now'],
            'featured' => ['type' => 'boolean'],
        ],
    ]);

    expect($result->data)->toBe([
        'headline' => 'Sample headl',
        'tone' => 'warm',
        'items' => [['n' => 3], ['n' => 3]],
        'cta' => 'Shop now',
        'featured' => true,
    ]);
});

test('synthesised data is deterministic', function () {
    $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'number']]];

    expect((new FakeAiProvider)->generateJson('s', 'p', $schema)->data)
        ->toBe((new FakeAiProvider)->generateJson('s', 'p', $schema)->data);
});

test('scripted responses are consumed in order', function () {
    $fake = (new FakeAiProvider)->push(['n' => 1], ['n' => 2]);
    $schema = ['type' => 'object'];

    expect($fake->generateJson('s', 'p', $schema)->data)->toBe(['n' => 1])
        ->and($fake->generateJson('s', 'p', $schema)->data)->toBe(['n' => 2]);
});

test('a scripted string is treated as raw output and must be JSON', function () {
    $fake = (new FakeAiProvider)->push('{"ok":true}', 'not json');

    expect($fake->generateJson('s', 'p', [])->data)->toBe(['ok' => true]);

    try {
        $fake->generateJson('s', 'p', []);
        $this->fail('Expected AiResponseException');
    } catch (AiResponseException $e) {
        expect($e->raw)->toBe('not json');
    }
});

test('scripted exceptions are thrown', function () {
    $fake = (new FakeAiProvider)->push(new AiConnectionException('down'));

    $fake->generateJson('s', 'p', []);
})->throws(AiConnectionException::class, 'down');

test('a closure response receives the call record', function () {
    $fake = (new FakeAiProvider)->push(fn (array $call) => ['echo' => $call['prompt']]);

    expect($fake->generateJson('s', 'hello', [])->data)->toBe(['echo' => 'hello']);
});

test('fixtures are loaded by name', function () {
    $fake = new FakeAiProvider(base_path('tests/Fixtures/ai'));

    expect($fake->generateJson('s', 'p', [], ['fixture' => 'store-name'])->data)
        ->toMatchArray(['store_name' => 'Clay & Co']);
});

test('calls are recorded and can be asserted', function () {
    $fake = new FakeAiProvider;
    $fake->assertNothingCalled();

    $fake->generateJson('sys', 'make a hero', ['type' => 'object'], ['task' => 'copy']);
    $fake->generateText('sys', 'describe');
    $fake->generateFromImage('sys', 'import', '/tmp/x.png', ['type' => 'object']);

    $fake->assertCallCount(3);
    $fake->assertCalled(fn (array $call) => $call['method'] === 'generateJson' && $call['prompt'] === 'make a hero');
    $fake->assertCalled(fn (array $call) => $call['method'] === 'generateFromImage' && $call['options']['task'] === 'vision');
});

test('capabilities can be switched off to test fallbacks', function () {
    $fake = (new FakeAiProvider)->withoutCapability(Capability::Vision);

    expect($fake->supports(Capability::Vision))->toBeFalse();

    $fake->generateFromImage('s', 'p', '/tmp/x.png', []);
})->throws(UnsupportedCapabilityException::class);
