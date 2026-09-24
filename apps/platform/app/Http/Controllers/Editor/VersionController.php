<?php

namespace App\Http\Controllers\Editor;

use App\Domain\Editor\ProjectDocument;
use App\Domain\Editor\VersionRecorder;
use App\Enums\ProjectStatus;
use App\Enums\VersionReason;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VersionController extends Controller
{
    public function index(Team $team, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['versions' => self::summaries($project)]);
    }

    /**
     * "Save version" with a name; named versions are never pruned.
     */
    public function store(Request $request, Team $team, Project $project, VersionRecorder $versions): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['label' => ['required', 'string', 'max:120']]);

        $versions->record($project, VersionReason::Manual, $request->user(), trim(strip_tags($data['label'])));

        return response()->json(['versions' => self::summaries($project)], 201);
    }

    /**
     * Restore a snapshot. The current state is saved first, so a restore can be undone.
     */
    public function restore(
        Request $request,
        Team $team,
        Project $project,
        string $version,
        VersionRecorder $versions,
        ProjectDocument $document,
    ): JsonResponse {
        Gate::authorize('update', $project);

        if ($project->status === ProjectStatus::Generating) {
            throw ValidationException::withMessages(['version' => __('Wait for the generation to finish before restoring.')]);
        }

        $target = ProjectVersion::where('project_id', $project->id)->findOrFail($version);

        $versions->record($project, VersionReason::PreRestore, $request->user());
        $document->import($project, $target->snapshot);

        return response()->json([
            'document' => $document->export($project->fresh()),
            'versions' => self::summaries($project),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function summaries(Project $project): array
    {
        return ProjectVersion::query()
            ->where('project_id', $project->id)
            ->with('author:id,name')
            ->latest('number')
            ->limit(30)
            ->get(['id', 'number', 'reason', 'label', 'created_by', 'created_at'])
            ->map(fn (ProjectVersion $v) => [
                'id' => $v->id,
                'number' => $v->number,
                'reason' => $v->reason->value,
                'reasonLabel' => $v->reason->label(),
                'label' => $v->label,
                'author' => $v->author?->name,
                'createdAt' => $v->created_at->toIso8601String(),
            ])
            ->all();
    }
}
