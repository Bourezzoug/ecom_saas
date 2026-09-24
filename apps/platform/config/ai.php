<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI driver
    |--------------------------------------------------------------------------
    |
    | Business code depends only on App\Domain\Ai\Contracts\AiProvider. Adding a
    | driver (e.g. Anthropic, OpenAI) means adding a class and a config block.
    |
    | Supported: "ollama", "fake"
    |
    */

    'driver' => env('AI_DRIVER', 'ollama'),

    'drivers' => [

        'ollama' => [
            'host' => env('OLLAMA_HOST', 'http://ollama:11434'),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 180),
            'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 5),
            'keep_alive' => env('OLLAMA_KEEP_ALIVE', '10m'),
            // Transport-level retries (connection errors / 5xx). Invalid JSON is
            // never retried here; the StructuredGenerator runs the repair pass.
            'retries' => (int) env('OLLAMA_RETRIES', 2),
            'retry_sleep_ms' => 500,
            'options' => [
                'temperature' => 0.4,
                'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
            ],
            // Reasoning models (qwen3, deepseek-r1...) must not "think" into the JSON output.
            'think' => false,
        ],

        'fake' => [
            'fixtures' => base_path('tests/Fixtures/ai'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Per-task model routing
    |--------------------------------------------------------------------------
    |
    | GenerationTask::modelRoute() picks one of these routes.
    |
    */

    'models' => [
        'planner' => env('AI_MODEL_PLANNER', 'qwen3:4b'),
        'copy' => env('AI_MODEL_COPY', 'qwen3:4b'),
        'vision' => env('AI_MODEL_VISION', 'qwen2.5vl:3b'),
    ],

    // Automatic repair attempts after a schema validation failure (spec §3.2).
    'repair_attempts' => 1,

];
