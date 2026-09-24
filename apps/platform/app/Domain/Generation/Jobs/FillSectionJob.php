<?php

namespace App\Domain\Generation\Jobs;

use App\Domain\Ai\StructuredGenerator;
use App\Domain\Generation\GenerationOutcome;
use App\Domain\Generation\SectionWriter;
use App\Enums\GenerationStatus;
use App\Enums\GenerationTask;
use App\Enums\SectionStatus;
use App\Models\Generation;
use App\Models\PageSection;
use App\Models\Project;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pass 2: write the content of one section. Never throws for AI problems: a
 * failed section keeps its schema defaults and is marked "failed" so the rest
 * of the store still gets generated.
 */
class FillSectionJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 240; // 2 × 90s calls + transport retry

    public function __construct(
        public string $projectId,
        public string $sectionId,
        public string $rootGenerationId,
    ) {
        $this->onQueue('ai');
    }

    public function handle(SectionWriter $writer, StructuredGenerator $generator): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $project = Project::findOrFail($this->projectId);
        $section = PageSection::with('page')->findOrFail($this->sectionId);
        $root = Generation::findOrFail($this->rootGenerationId);

        $section->forceFill(['status' => SectionStatus::Generating])->save();
        GenerationOutcome::notify($project->id, 'writing', $section->id, 'generating');

        $generation = Generation::create([
            'parent_id' => $root->id,
            'team_id' => $project->team_id,
            'project_id' => $project->id,
            'user_id' => $root->user_id,
            'task' => GenerationTask::FillSection,
            'status' => GenerationStatus::Queued,
        ]);
        $generation->subject()->associate($section)->save();

        try {
            $content = $generator->run($generation, $writer->request($project, $section));

            $section->forceFill([
                'content' => $content,
                'status' => SectionStatus::Ready,
                'last_generation_id' => $generation->id,
            ])->save();
        } catch (Throwable $e) {
            report($e);

            $section->forceFill([
                'status' => SectionStatus::Failed,
                'last_generation_id' => $generation->id,
            ])->save();
        }

        GenerationOutcome::notify($project->id, 'writing', $section->id, $section->status->value);
    }

    /**
     * The job itself died (timeout): don't leave the section spinning.
     */
    public function failed(?Throwable $exception): void
    {
        PageSection::whereKey($this->sectionId)->update(['status' => SectionStatus::Failed->value]);
        GenerationOutcome::notify($this->projectId, 'writing', $this->sectionId, 'failed');
    }
}
