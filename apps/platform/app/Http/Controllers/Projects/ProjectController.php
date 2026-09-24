<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Credits\CreditLedger;
use App\Domain\Generation\GenerationProgress;
use App\Domain\Generation\StartStoreGeneration;
use App\Enums\BrandTone;
use App\Enums\CreationMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SaveProjectRequest;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * List the current team's projects.
     */
    public function index(Request $request, Team $team): Response
    {
        Gate::authorize('viewAny', [Project::class, $team]);

        $projects = $team->projects()
            ->with('creator:id,name')
            ->latest('updated_at')
            ->paginate(24)
            ->through(fn (Project $project) => $this->summary($project));

        return Inertia::render('projects/index', [
            'projects' => $projects,
        ]);
    }

    /**
     * Show the new-project form.
     */
    public function create(Request $request, Team $team): Response
    {
        Gate::authorize('create', [Project::class, $team]);

        return Inertia::render('projects/create', $this->formOptions());
    }

    /**
     * Create a project in the current team.
     */
    public function store(SaveProjectRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('create', [Project::class, $team]);

        $project = new Project($request->projectAttributes());
        $project->team()->associate($team);
        $project->created_by = $request->user()->id;
        $project->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.show', ['current_team' => $team->slug, 'project' => $project->id]);
    }

    /**
     * Show a project overview.
     */
    public function show(Request $request, Team $team, Project $project, GenerationProgress $progress, CreditLedger $credits): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/show', [
            'project' => fn () => $this->detail($project->load('creator:id,name')),
            'generation' => fn () => $progress->for($project),
            'generateCost' => $credits->cost(StartStoreGeneration::ACTION),
            'can' => [
                'update' => $request->user()->can('update', $project),
                'delete' => $request->user()->can('delete', $project),
            ],
        ]);
    }

    /**
     * Show the edit form.
     */
    public function edit(Request $request, Team $team, Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('projects/edit', [
            'project' => $this->detail($project),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Update the project's settings and brief.
     */
    public function update(SaveProjectRequest $request, Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->fill($request->projectAttributes());

        if ($project->isDirty()) {
            $project->revision++;
            $project->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.show', ['current_team' => $team->slug, 'project' => $project->id]);
    }

    /**
     * Soft-delete the project.
     */
    public function destroy(Request $request, Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('projects.index', ['current_team' => $team->slug]);
    }

    /**
     * Options for the create/edit form.
     *
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        /** @var array<string, array{name: string, dir: string}> $languages */
        $languages = config('languages');

        return [
            'languages' => collect($languages)
                ->map(fn (array $language, string $code) => ['value' => $code, 'label' => $language['name'], 'dir' => $language['dir']])
                ->values(),
            'tones' => BrandTone::values(),
            'creationModes' => collect(CreationMode::cases())->map(fn (CreationMode $mode) => [
                'value' => $mode->value,
                'label' => $mode->label(),
                // Import flows ship in later milestones (design import M2, product CSV M4).
                'available' => $mode === CreationMode::Describe,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status->value,
            'statusLabel' => $project->status->label(),
            'language' => $project->language,
            'niche' => $project->brief['niche'] ?? null,
            'creator' => $project->creator?->name,
            'updatedAt' => $project->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Project $project): array
    {
        return [
            ...$this->summary($project),
            'kind' => $project->kind->value,
            'creationMode' => $project->creation_mode->value,
            'direction' => $project->direction->value,
            'currency' => $project->currency,
            'brief' => [
                'niche' => $project->brief['niche'] ?? '',
                'audience' => $project->brief['audience'] ?? null,
                'tone' => $project->brief['tone'] ?? null,
                'style' => $project->brief['style'] ?? null,
                'brand_colors' => $project->brief['brand_colors'] ?? [],
            ],
            'createdAt' => $project->created_at?->toIso8601String(),
        ];
    }
}
