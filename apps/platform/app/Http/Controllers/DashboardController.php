<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        $team = $request->user()->currentTeam;

        return Inertia::render('dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'projectCount' => $team?->projects()->count() ?? 0,
            'recentProjects' => $team?->projects()
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status->value,
                    'statusLabel' => $project->status->label(),
                    'niche' => $project->brief['niche'] ?? null,
                    'updatedAt' => $project->updated_at?->toIso8601String(),
                ]) ?? [],
        ]);
    }
}
