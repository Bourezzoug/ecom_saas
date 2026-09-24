<?php

namespace App\Domain\Generation;

use App\Domain\Credits\CreditLedger;
use App\Domain\Generation\Exceptions\GenerationInProgressException;
use App\Domain\Generation\Jobs\PlanSiteJob;
use App\Enums\GenerationStatus;
use App\Enums\GenerationTask;
use App\Enums\ProjectStatus;
use App\Models\Generation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Generate store" user action:
 *  1. atomically flip the project to "generating" (a second click fails fast)
 *  2. create the root generations row and charge credits for the whole action
 *  3. queue the planner after the transaction commits
 *
 * Insufficient credits roll the whole thing back.
 */
class StartStoreGeneration
{
    public const ACTION = 'generate_store';

    public function __construct(private readonly CreditLedger $credits) {}

    public function handle(Project $project, User $user): Generation
    {
        return DB::transaction(function () use ($project, $user) {
            $claimed = Project::query()
                ->whereKey($project->getKey())
                ->where('status', '!=', ProjectStatus::Generating->value)
                ->update(['status' => ProjectStatus::Generating->value, 'updated_at' => now()]);

            if ($claimed === 0) {
                throw new GenerationInProgressException('This project is already being generated.');
            }

            $root = Generation::create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'task' => GenerationTask::PlanSite,
                'action_key' => self::ACTION,
                'status' => GenerationStatus::Queued,
                'credits' => $this->credits->cost(self::ACTION),
            ]);

            $this->credits->spend(
                $project->team,
                self::ACTION,
                $user,
                $root,
                idempotencyKey: 'generation:'.$root->id,
                meta: ['project_id' => $project->id],
            );

            PlanSiteJob::dispatch($project->id, $root->id)->afterCommit();

            $project->status = ProjectStatus::Generating;

            return $root;
        });
    }
}
