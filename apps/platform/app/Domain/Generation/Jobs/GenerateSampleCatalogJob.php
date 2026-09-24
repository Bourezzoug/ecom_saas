<?php

namespace App\Domain\Generation\Jobs;

use App\Domain\Ai\StructuredGenerator;
use App\Domain\Generation\CatalogWriter;
use App\Domain\Generation\GenerationOutcome;
use App\Enums\GenerationStatus;
use App\Enums\GenerationTask;
use App\Models\Generation;
use App\Models\Project;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Part of "generate store" when the project has no products yet. A failure
 * here never fails the store: the sections still work with sample data.
 */
class GenerateSampleCatalogJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 330;

    public function __construct(
        public string $projectId,
        public string $rootGenerationId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(CatalogWriter $writer, StructuredGenerator $generator): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $project = Project::findOrFail($this->projectId);

        if ($project->products()->exists()) {
            return;
        }

        $root = Generation::findOrFail($this->rootGenerationId);
        $generation = Generation::create([
            'parent_id' => $root->id,
            'team_id' => $project->team_id,
            'project_id' => $project->id,
            'user_id' => $root->user_id,
            'task' => GenerationTask::ProductDescription,
            'status' => GenerationStatus::Queued,
        ]);

        try {
            /** @var array{categories: list<array{name: string}>, products: list<array<string, mixed>>} $catalog */
            $catalog = $generator->run($generation, $writer->request($project));
            $writer->apply($project, $catalog);
        } catch (Throwable $e) {
            report($e);
        }

        GenerationOutcome::notify($project->id, 'writing');
    }
}
