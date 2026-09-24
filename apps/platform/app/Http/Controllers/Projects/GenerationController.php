<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Credits\Exceptions\InsufficientCreditsException;
use App\Domain\Generation\Exceptions\GenerationInProgressException;
use App\Domain\Generation\StartStoreGeneration;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GenerationController extends Controller
{
    /**
     * Start (or restart) AI generation of the whole store.
     */
    public function store(Request $request, Team $team, Project $project, StartStoreGeneration $start): RedirectResponse
    {
        Gate::authorize('update', $project);

        try {
            $start->handle($project, $request->user());
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages(['generation' => __('Not enough credits: this needs :required, you have :available.', [
                'required' => $e->required,
                'available' => $e->available,
            ])]);
        } catch (GenerationInProgressException) {
            throw ValidationException::withMessages(['generation' => __('This store is already being generated.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation started.')]);

        return to_route('projects.show', ['current_team' => $team->slug, 'project' => $project->id]);
    }
}
