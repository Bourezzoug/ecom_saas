<?php

namespace App\Http\Controllers\Editor;

use Aisg\Sections\Icons;
use App\Domain\Credits\CreditLedger;
use App\Domain\Design\FontCatalog;
use App\Domain\Editor\ProjectDocument;
use App\Domain\Generation\RegenerateSection;
use App\Domain\Preview\CatalogData;
use App\Domain\Sections\SectionLibrary;
use App\Domain\Sections\SectionPayload;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EditorController extends Controller
{
    /**
     * The visual editor. Everything the client needs to render the store
     * locally (section library, document, sample data) comes in one payload.
     */
    public function show(
        Request $request,
        Team $team,
        Project $project,
        ProjectDocument $document,
        SectionLibrary $library,
        CreditLedger $credits,
    ): Response|RedirectResponse {
        Gate::authorize('update', $project);

        if ($project->pages()->doesntExist()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Generate the store first, then open the editor.')]);

            return to_route('projects.show', ['current_team' => $team->slug, 'project' => $project->id]);
        }

        return Inertia::render('projects/editor', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'language' => $project->language,
                'direction' => $project->direction->value,
                'currency' => $project->currency,
                'status' => $project->status->value,
            ],
            'document' => fn () => $document->export($project),
            'library' => collect($library->registry()->all())->map(fn ($d) => SectionPayload::for($d))->values(),
            'catalog' => fn () => CatalogData::for($project),
            'fonts' => FontCatalog::forLanguage($project->language),
            'icons' => Icons::NAMES,
            'regenerateCost' => $credits->cost(RegenerateSection::ACTION),
            'versions' => fn () => VersionController::summaries($project),
            'assetsUrl' => route('sections.assets', ['file' => '__FILE__']),
        ]);
    }
}
