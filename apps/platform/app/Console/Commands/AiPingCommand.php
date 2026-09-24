<?php

namespace App\Console\Commands;

use App\Domain\Ai\AiManager;
use App\Domain\Ai\Exceptions\AiException;
use Illuminate\Console\Command;

class AiPingCommand extends Command
{
    protected $signature = 'ai:ping
                            {--task=copy : Model route to test (planner, copy, vision)}
                            {--driver= : Driver to use instead of AI_DRIVER}';

    protected $description = 'Send a tiny structured-output request to the configured AI driver';

    public function handle(AiManager $ai): int
    {
        $provider = $ai->provider($this->option('driver'));
        $task = (string) $this->option('task');

        $this->components->info("Driver [{$provider->name()}], route [{$task}] → model [".($ai->modelFor($task) ?? 'n/a').']');

        $schema = [
            'type' => 'object',
            'properties' => [
                'store_name' => ['type' => 'string'],
                'tagline' => ['type' => 'string'],
                'tone' => ['type' => 'string', 'enum' => ['playful', 'premium', 'friendly']],
            ],
            'required' => ['store_name', 'tagline', 'tone'],
        ];

        try {
            $result = $provider->generateJson(
                'You name online stores. Reply with JSON only.',
                'Invent a store selling handmade ceramic mugs.',
                $schema,
                ['task' => $task, 'max_tokens' => 200],
            );
        } catch (AiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->twoColumnDetail('Model', $result->model);
        $this->components->twoColumnDetail('Tokens in / out', "{$result->tokensIn} / {$result->tokensOut}");
        $this->components->twoColumnDetail('Duration', "{$result->durationMs} ms");

        return self::SUCCESS;
    }
}
