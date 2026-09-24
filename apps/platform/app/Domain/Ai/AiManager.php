<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\Drivers\FakeAiProvider;
use App\Domain\Ai\Drivers\OllamaProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;

/**
 * Resolves AI drivers from config/ai.php.
 *
 * @mixin AiProvider
 */
class AiManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('ai.driver', 'ollama');
    }

    public function provider(?string $driver = null): AiProvider
    {
        return $this->driver($driver);
    }

    /**
     * The model name configured for a route ("planner", "copy", "vision").
     */
    public function modelFor(string $route): ?string
    {
        return $this->config->get("ai.models.{$route}");
    }

    protected function createOllamaDriver(): OllamaProvider
    {
        return new OllamaProvider(
            $this->container->make(HttpFactory::class),
            $this->config->get('ai.drivers.ollama'),
            $this->config->get('ai.models', []),
        );
    }

    protected function createFakeDriver(): FakeAiProvider
    {
        return new FakeAiProvider($this->config->get('ai.drivers.fake.fixtures'));
    }

    /**
     * Swap the default driver (and the container binding) for a FakeAiProvider.
     */
    public function fake(?FakeAiProvider $fake = null): FakeAiProvider
    {
        $fake ??= $this->createFakeDriver();

        $this->drivers[$this->getDefaultDriver()] = $fake;
        $this->container->instance(AiProvider::class, $fake);

        return $fake;
    }
}
