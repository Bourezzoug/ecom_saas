<?php

namespace App\Domain\Publishing\Jobs;

use App\Domain\Publishing\Publisher;
use App\Domain\Publishing\ZipExporter;
use App\Models\Publish;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a publish (push to WordPress, or ZIP export) on the "publish" queue.
 */
class PublishProjectJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public string $publishId)
    {
        $this->onQueue('publish');
    }

    public function handle(Publisher $publisher, ZipExporter $exporter): void
    {
        $publish = Publish::with(['project', 'connection'])->findOrFail($this->publishId);

        $publish->target === 'zip' ? $exporter->run($publish) : $publisher->run($publish);
    }

    public function failed(?Throwable $exception): void
    {
        Publish::whereKey($this->publishId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error' => $exception?->getMessage() ?? 'The publish job stopped unexpectedly.',
                'finished_at' => now(),
            ]);
    }
}
