<?php

namespace App\Domain\Generation\Jobs;

use App\Domain\Ai\StructuredGenerator;
use App\Domain\Credits\CreditLedger;
use App\Domain\Generation\GenerationOutcome;
use App\Domain\Generation\SectionWriter;
use App\Enums\SectionStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\PageSection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rewrite one section's copy. On failure the previous content stays untouched
 * and the credits are refunded.
 */
class RegenerateSectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240; // 2 × 90s calls + transport retry

    public function __construct(
        public string $sectionId,
        public string $rootGenerationId,
        public ?string $instruction = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(SectionWriter $writer, StructuredGenerator $generator): void
    {
        $section = PageSection::with('page.project')->findOrFail($this->sectionId);
        $root = Generation::findOrFail($this->rootGenerationId);
        $project = $section->page->project;

        try {
            $content = $generator->run($root, $writer->request($project, $section, $this->instruction));
        } catch (Throwable $e) {
            report($e);
            $this->fail_($section, $root, $e->getMessage());

            return;
        }

        $section->forceFill([
            'content' => $content,
            'status' => SectionStatus::Ready,
            'last_generation_id' => $root->id,
            'lock_version' => $section->lock_version + 1,
        ])->save();
        $project->increment('revision');

        GenerationOutcome::notify($project->id, 'section', $section->id, 'ready');
    }

    public function failed(?Throwable $exception): void
    {
        $section = PageSection::with('page')->find($this->sectionId);
        $root = Generation::find($this->rootGenerationId);

        if ($section && $root) {
            $this->fail_($section, $root, $exception?->getMessage() ?? 'Job failed');
        }
    }

    private function fail_(PageSection $section, Generation $root, string $reason): void
    {
        $section->forceFill(['status' => SectionStatus::Ready])->save();

        $spend = CreditTransaction::query()->where('generation_id', $root->id)->where('type', 'spend')->first();

        if ($spend !== null) {
            app(CreditLedger::class)->refund($spend, 'Section rewrite failed: '.$reason);
        }

        GenerationOutcome::notify($section->page->project_id, 'section', $section->id, 'failed');
    }
}
