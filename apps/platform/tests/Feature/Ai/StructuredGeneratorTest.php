<?php

use App\Domain\Ai\AiManager;
use App\Domain\Ai\Exceptions\AiConnectionException;
use App\Domain\Ai\Exceptions\GenerationFailedException;
use App\Domain\Ai\JsonSchemaValidator;
use App\Domain\Ai\OutputSanitizer;
use App\Domain\Ai\StructuredGenerator;
use App\Domain\Ai\StructuredRequest;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\Team;

beforeEach(function () {
    $this->fake = app(AiManager::class)->fake();
    $this->generation = Generation::create(['team_id' => Team::factory()->create()->id, 'task' => 'fill_section']);
});

function headlineRequest(): StructuredRequest
{
    $schema = [
        'type' => 'object',
        'required' => ['headline'],
        'properties' => ['headline' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 20]],
    ];

    return new StructuredRequest(
        route: 'copy',
        system: 'system',
        prompt: 'write a headline',
        schema: ['type' => 'object', 'properties' => ['headline' => ['type' => 'string']], 'required' => ['headline']],
        prepare: fn (array $data) => (new OutputSanitizer)->sanitize($data, $schema),
        validate: fn (array $data) => (new JsonSchemaValidator)->errors($data, $schema),
    );
}

test('valid output is accepted on the first attempt and logged', function () {
    $this->fake->push(['headline' => 'Mugs by hand']);

    $output = app(StructuredGenerator::class)->run($this->generation, headlineRequest());

    expect($output)->toBe(['headline' => 'Mugs by hand']);

    $g = $this->generation->fresh();
    expect($g->status)->toBe(GenerationStatus::Succeeded)
        ->and($g->attempts)->toBe(1)
        ->and($g->provider)->toBe('fake')
        ->and($g->output)->toBe(['headline' => 'Mugs by hand'])
        ->and($g->prompt)->toBe('write a headline')
        ->and($g->json_schema['required'])->toBe(['headline'])
        ->and($g->tokens_out)->toBe(20)
        ->and($g->finished_at)->not->toBeNull();
});

test('output is sanitised before validation (tags stripped, long text truncated)', function () {
    $this->fake->push(['headline' => '<b>Beautiful</b> handmade ceramic mugs for everyone']);

    $output = app(StructuredGenerator::class)->run($this->generation, headlineRequest());

    expect($output['headline'])->toBe('Beautiful handmade');
});

test('invalid output gets exactly one repair attempt with the errors', function () {
    $this->fake->push(['headline' => 'Hi'], ['headline' => 'Hello mugs']);

    $output = app(StructuredGenerator::class)->run($this->generation, headlineRequest());

    expect($output)->toBe(['headline' => 'Hello mugs'])
        ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Repaired)
        ->and($this->generation->fresh()->attempts)->toBe(2);

    $repair = $this->fake->calls()[1];
    expect($repair['prompt'])->toContain('/headline')->toContain('corrected JSON')
        ->and(array_column($repair['options']['messages'], 'role'))->toBe(['user', 'assistant'])
        ->and($repair['options']['messages'][1]['content'])->toBe('{"headline":"Hi"}');
});

test('non-JSON output is repaired like a validation error', function () {
    $this->fake->push('Sure! Here it is: Hello', ['headline' => 'Hello mugs']);

    expect(app(StructuredGenerator::class)->run($this->generation, headlineRequest()))->toBe(['headline' => 'Hello mugs'])
        ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Repaired);
});

test('output still invalid after the repair fails with the errors logged', function () {
    $this->fake->push(['headline' => 'Hi'], ['headline' => 'No']);

    try {
        app(StructuredGenerator::class)->run($this->generation, headlineRequest());
        $this->fail('Expected GenerationFailedException');
    } catch (GenerationFailedException $e) {
        expect($e->errors)->not->toBeEmpty();
    }

    $g = $this->generation->fresh();
    expect($g->status)->toBe(GenerationStatus::Failed)
        ->and($g->attempts)->toBe(2)
        ->and($g->raw_output)->toBe('{"headline":"No"}')
        ->and($g->validation_errors)->not->toBeEmpty();

    $this->fake->assertCallCount(2);
});

test('transport failures are not repaired', function () {
    $this->fake->push(new AiConnectionException('Ollama is down'));

    expect(fn () => app(StructuredGenerator::class)->run($this->generation, headlineRequest()))
        ->toThrow(GenerationFailedException::class, 'Ollama is down');

    $this->fake->assertCallCount(1);
    expect($this->generation->fresh()->error)->toBe('Ollama is down');
});

test('the sanitiser strips scripts, markdown and control characters', function () {
    $sanitizer = new OutputSanitizer;

    expect($sanitizer->cleanString("  <script>steal()</script>**Hand**made &amp; <i>good</i>\u{0007}\n\n\nmugs  "))
        ->toBe("Handmade & good\nmugs")
        ->and($sanitizer->sanitize(['a', 'b', 'c'], ['type' => 'array', 'maxItems' => 2]))->toBe(['a', 'b']);
});
