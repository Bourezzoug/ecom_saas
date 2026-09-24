<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Generation progress for one project: any member of the owning team may listen.
Broadcast::channel('projects.{projectId}', function (User $user, string $projectId) {
    $project = Project::find($projectId);

    return $project !== null && $user->belongsToTeam($project->team);
});
