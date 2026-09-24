<?php

namespace App\Domain\Editor;

use App\Enums\VersionReason;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates project_versions snapshots and keeps the history bounded.
 *
 *  - record(): an explicit snapshot (generation done, before AI rewrite, manual, before restore)
 *  - touch():  called after every editor write; snapshots at most once per AUTOSAVE_MINUTES
 *  - retention: the newest KEEP_UNNAMED unlabelled versions are kept; labelled ones forever
 */
class VersionRecorder
{
    public const AUTOSAVE_MINUTES = 10;

    public const KEEP_UNNAMED = 50;

    public function __construct(private readonly ProjectDocument $document) {}

    public function record(Project $project, VersionReason $reason, ?User $user = null, ?string $label = null): ProjectVersion
    {
        return DB::transaction(function () use ($project, $reason, $user, $label) {
            // Lock the project row so concurrent snapshots get distinct numbers.
            Project::query()->whereKey($project->getKey())->lockForUpdate()->first();

            $version = ProjectVersion::create([
                'project_id' => $project->id,
                'number' => (int) ProjectVersion::where('project_id', $project->id)->max('number') + 1,
                'reason' => $reason,
                'label' => $label,
                'snapshot' => $this->document->export($project),
                'created_by' => $user?->id,
            ]);

            $this->prune($project);

            return $version;
        });
    }

    /**
     * Autosave snapshot, throttled: returns null when a recent version exists.
     */
    public function touch(Project $project, ?User $user = null): ?ProjectVersion
    {
        $recent = ProjectVersion::where('project_id', $project->id)
            ->where('created_at', '>=', now()->subMinutes(self::AUTOSAVE_MINUTES))
            ->exists();

        return $recent ? null : $this->record($project, VersionReason::Autosave, $user);
    }

    private function prune(Project $project): void
    {
        $keep = ProjectVersion::where('project_id', $project->id)
            ->whereNull('label')
            ->orderByDesc('number')
            ->limit(self::KEEP_UNNAMED)
            ->pluck('id');

        ProjectVersion::where('project_id', $project->id)
            ->whereNull('label')
            ->whereNotIn('id', $keep)
            ->delete();
    }
}
