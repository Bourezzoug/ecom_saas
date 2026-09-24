<?php

namespace App\Domain\Generation\Jobs;

use App\Domain\Ai\StructuredGenerator;
use App\Domain\Generation\GenerationOutcome;
use App\Domain\Generation\SitePlanApplier;
use App\Domain\Generation\SitePlanner;
use App\Models\Generation;
use App\Models\PageSection;
use App\Models\Project;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Pass 1: ask the planner for the site structure, persist it, then fan out one
 * FillSectionJob per section. A GenerationOutcome closes the action when the
 * batch finishes.
 */
class PlanSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(
        public string $projectId,
        public string $rootGenerationId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        SitePlanner $planner,
        StructuredGenerator $generator,
        SitePlanApplier $applier,
        GenerationOutcome $outcome,
    ): void {
        $project = Project::findOrFail($this->projectId);
        $root = Generation::findOrFail($this->rootGenerationId);

        GenerationOutcome::notify($project->id, 'planning');

        try {
            $plan = $generator->run($root, $planner->request($project));
            $sections = $applier->apply($project, $plan);
        } catch (Throwable $e) {
            $outcome->fail($project, $root, 'Planning failed: '.$e->getMessage());

            return;
        }

        GenerationOutcome::notify($project->id, 'writing');

        $projectId = $project->id;
        $rootId = $root->id;

        $jobs = array_map(
            fn (PageSection $section) => new FillSectionJob($projectId, $section->id, $rootId),
            $sections,
        );

        // Stores without products get an AI sample catalog (drafts) in the same batch.
        if ($project->products()->doesntExist()) {
            $jobs[] = new GenerateSampleCatalogJob($projectId, $rootId);
        }

        Bus::batch($jobs)
            ->name("generate-store:{$projectId}")
            ->onQueue('ai')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($projectId, $rootId) {
                app(GenerationOutcome::class)->finish(
                    Project::findOrFail($projectId),
                    Generation::findOrFail($rootId),
                );
            })
            ->dispatch();
    }

    /**
     * Called when the job itself dies (timeout, worker crash): refund and unlock.
     */
    public function failed(?Throwable $exception): void
    {
        $project = Project::find($this->projectId);
        $root = Generation::find($this->rootGenerationId);

        if ($project && $root) {
            app(GenerationOutcome::class)->fail($project, $root, 'Planner job failed: '.($exception?->getMessage() ?? 'unknown'));
        }
    }
}
