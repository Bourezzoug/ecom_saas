<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Preview\PagePreview;
use App\Enums\PageKind;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PreviewController extends Controller
{
    /**
     * Full-page HTML preview of one generated page (defaults to the homepage).
     */
    public function show(Request $request, Team $team, Project $project, PagePreview $preview, ?string $page = null): Response
    {
        Gate::authorize('view', $project);

        $pages = $project->pages()->where('kind', PageKind::Page->value)->get();

        $current = $page !== null
            ? $pages->firstWhere('id', $page)
            : ($pages->firstWhere('is_homepage', true) ?? $pages->first());

        abort_if($current === null, 404);

        $html = $preview->render(
            $project,
            $current,
            pageUrl: fn (Page $p) => route('projects.preview', ['current_team' => $team->slug, 'project' => $project->id, 'page' => $p->id]),
            assetUrl: fn (string $file) => route('sections.assets', ['file' => $file]),
        );

        return response($html)->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'no-store',
        ]);
    }
}
