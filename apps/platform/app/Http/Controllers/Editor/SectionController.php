<?php

namespace App\Http\Controllers\Editor;

use App\Domain\Credits\Exceptions\InsufficientCreditsException;
use App\Domain\Editor\Exceptions\StaleSectionException;
use App\Domain\Editor\ProjectDocument;
use App\Domain\Editor\SectionEditor;
use App\Domain\Generation\Exceptions\GenerationInProgressException;
use App\Domain\Generation\RegenerateSection;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Editor\Concerns\FindsProjectRecords;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * JSON endpoints behind the editor. All writes are manual edits (free),
 * except regenerate(), which charges credits.
 */
class SectionController extends Controller
{
    use FindsProjectRecords;

    public function __construct(
        private readonly SectionEditor $editor,
        private readonly ProjectDocument $document,
    ) {}

    public function show(Team $team, Project $project, string $section): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['section' => $this->document->section($this->findSection($project, $section))]);
    }

    public function update(Request $request, Team $team, Project $project, string $section): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'content' => ['sometimes', 'array'],
            'style' => ['sometimes', 'array'],
            'lockVersion' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $saved = $this->editor->update(
                $this->findSection($project, $section),
                $request->has('content') ? $request->input('content') : null,
                $request->has('style') ? $request->input('style') : null,
                (int) $data['lockVersion'],
                $request->user(),
            );
        } catch (StaleSectionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'section' => $e->current ? $this->document->section($e->current) : null,
            ], 409);
        }

        return response()->json(['section' => $this->document->section($saved), 'revision' => $project->fresh()->revision]);
    }

    public function store(Request $request, Team $team, Project $project, string $page): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $section = $this->editor->add($this->findPage($project, $page), $data['key'], $data['position'] ?? null, $request->user());

        return response()->json(['section' => $this->document->section($section)], 201);
    }

    public function destroy(Request $request, Team $team, Project $project, string $section): JsonResponse
    {
        Gate::authorize('update', $project);

        $this->editor->remove($this->findSection($project, $section), $request->user());

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, Team $team, Project $project, string $page): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:50'],
            'ids.*' => ['required', 'string', 'size:26'],
        ]);

        $this->editor->reorder($this->findPage($project, $page), $data['ids'], $request->user());

        return response()->json(['ok' => true]);
    }

    public function renamePage(Request $request, Team $team, Project $project, string $page): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['title' => ['required', 'string', 'max:60']]);

        $saved = $this->editor->renamePage($this->findPage($project, $page), trim(strip_tags($data['title'])), $request->user());

        return response()->json(['page' => ['id' => $saved->id, 'title' => $saved->title]]);
    }

    public function regenerate(Request $request, Team $team, Project $project, string $section, RegenerateSection $regenerate): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['instruction' => ['nullable', 'string', 'max:300']]);
        $target = $this->findSection($project, $section);

        try {
            $regenerate->handle($target, $request->user(), filled($data['instruction'] ?? null) ? trim(strip_tags($data['instruction'])) : null);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages(['regenerate' => __('Not enough credits: this needs :required, you have :available.', ['required' => $e->required, 'available' => $e->available])]);
        } catch (GenerationInProgressException $e) {
            throw ValidationException::withMessages(['regenerate' => $e->getMessage()]);
        }

        return response()->json([
            'section' => $this->document->section($target->fresh()),
            'credits' => $project->team->fresh()->credit_balance,
        ], 202);
    }
}
