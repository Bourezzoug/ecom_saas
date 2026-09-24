<?php

namespace App\Domain\Generation;

use App\Domain\Credits\CreditLedger;
use App\Domain\Editor\VersionRecorder;
use App\Enums\ProjectStatus;
use App\Enums\SectionStatus;
use App\Enums\VersionReason;
use App\Events\GenerationProgressed;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Final bookkeeping for a "generate store" action (credit policy, §9 Q5):
 *  - planner failed, or every section failed → project "failed", full refund
 *  - some sections failed → project "partial" (they keep their defaults), no refund
 *  - all sections ready → project "ready"
 */
class GenerationOutcome
{
    public function __construct(
        private readonly CreditLedger $credits,
        private readonly VersionRecorder $versions,
    ) {}

    public function finish(Project $project, Generation $root): void
    {
        $statuses = $project->sections()->pluck('status')->map(fn ($s) => $s instanceof SectionStatus ? $s : SectionStatus::from($s));
        $ready = $statuses->filter(fn (SectionStatus $s) => $s === SectionStatus::Ready)->count();

        if ($ready === 0) {
            $this->fail($project, $root, 'No section could be generated.');

            return;
        }

        $project->forceFill([
            'status' => $ready === $statuses->count() ? ProjectStatus::Ready : ProjectStatus::Partial,
            'revision' => $project->revision + 1,
        ])->save();

        // Restore point: the freshly generated store, before any manual edits.
        $this->versions->record($project, VersionReason::Generation, $root->user);

        self::notify($project->id, 'done');
    }

    /**
     * Mark the action failed and refund its credits. Safe to call twice.
     */
    public function fail(Project $project, Generation $root, string $reason): void
    {
        $project->forceFill(['status' => ProjectStatus::Failed])->save();

        $spend = CreditTransaction::query()
            ->where('generation_id', $root->id)
            ->where('type', 'spend')
            ->first();

        if ($spend !== null) {
            $this->credits->refund($spend, $reason);
        }

        Log::warning('Store generation failed', ['project_id' => $project->id, 'generation_id' => $root->id, 'reason' => $reason]);

        self::notify($project->id, 'failed');
    }

    /**
     * Broadcast a progress ping. Realtime is best-effort: if Reverb is down the
     * UI's polling fallback still picks the change up.
     */
    public static function notify(string $projectId, string $stage, ?string $sectionId = null, ?string $sectionStatus = null): void
    {
        rescue(
            fn () => GenerationProgressed::dispatch($projectId, $stage, $sectionId, $sectionStatus),
            report: false,
        );
    }
}
