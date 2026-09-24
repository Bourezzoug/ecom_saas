<?php

namespace App\Domain\Generation;

use App\Domain\Credits\CreditLedger;
use App\Domain\Editor\VersionRecorder;
use App\Domain\Generation\Exceptions\GenerationInProgressException;
use App\Domain\Generation\Jobs\RegenerateSectionJob;
use App\Enums\GenerationStatus;
use App\Enums\GenerationTask;
use App\Enums\ProjectStatus;
use App\Enums\SectionStatus;
use App\Enums\VersionReason;
use App\Models\Generation;
use App\Models\PageSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Regenerate this section with AI" (+ optional instruction). Costs
 * regenerate_section credits; refunded if the rewrite fails.
 */
class RegenerateSection
{
    public const ACTION = 'regenerate_section';

    public function __construct(
        private readonly CreditLedger $credits,
        private readonly VersionRecorder $versions,
    ) {}

    public function handle(PageSection $section, User $user, ?string $instruction = null): Generation
    {
        $project = $section->page->project;

        if ($project->status === ProjectStatus::Generating) {
            throw new GenerationInProgressException('The whole store is being generated.');
        }

        // Undo point before the AI overwrites anything.
        $this->versions->record($project, VersionReason::PreRegenerate, $user);

        return DB::transaction(function () use ($section, $project, $user, $instruction) {
            $claimed = PageSection::query()
                ->whereKey($section->id)
                ->where('status', '!=', SectionStatus::Generating->value)
                ->update(['status' => SectionStatus::Generating->value]);

            if ($claimed === 0) {
                throw new GenerationInProgressException('This section is already being rewritten.');
            }

            $root = Generation::create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'task' => GenerationTask::RegenerateSection,
                'action_key' => self::ACTION,
                'status' => GenerationStatus::Queued,
                'credits' => $this->credits->cost(self::ACTION),
            ]);
            $root->subject()->associate($section)->save();

            $this->credits->spend(
                $project->team,
                self::ACTION,
                $user,
                $root,
                idempotencyKey: 'generation:'.$root->id,
                meta: ['project_id' => $project->id, 'section_id' => $section->id],
            );

            RegenerateSectionJob::dispatch($section->id, $root->id, $instruction)->afterCommit();

            return $root;
        });
    }
}
