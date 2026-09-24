<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Generation state changed" ping on the project's private channel. The client
 * reloads the progress prop; the payload is only a hint for logging/animation.
 */
class GenerationProgressed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $projectId,
        public string $stage,
        public ?string $sectionId = null,
        public ?string $sectionStatus = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('projects.'.$this->projectId)];
    }

    public function broadcastAs(): string
    {
        return 'generation.progressed';
    }
}
