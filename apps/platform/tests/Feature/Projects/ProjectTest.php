<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A user with a personal team, and a helper to add them to other teams.
 */
function member(TeamRole $role = TeamRole::Owner, ?Team $team = null): array
{
    $team ??= Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

function validProjectPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Clay & Co',
        'creation_mode' => 'describe',
        'language' => 'en',
        'currency' => 'usd',
        'brief' => [
            'niche' => 'Handmade ceramic mugs',
            'audience' => 'Coffee lovers',
            'tone' => 'friendly',
            'style' => 'Earthy, minimal',
            'brand_colors' => ['#7c4a1e', ''],
        ],
    ], $overrides);
}

test('guests are redirected to login', function () {
    $team = Team::factory()->create();

    $this->get(route('projects.index', ['current_team' => $team->slug]))->assertRedirect(route('login'));
});

test('members see only their team projects', function () {
    [$user, $team] = member(TeamRole::Member);
    $mine = Project::factory()->for($team)->create(['name' => 'Mine']);
    Project::factory()->create(['name' => 'Someone else']);

    $this->actingAs($user)
        ->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $mine->id)
            ->where('projects.data.0.name', 'Mine'));
});

test('non-members cannot list a team projects', function () {
    [$user] = member();
    $otherTeam = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.index', ['current_team' => $otherTeam->slug]))
        ->assertForbidden();
});

test('the create page lists languages, tones and creation modes', function () {
    [$user, $team] = member(TeamRole::Member);

    $this->actingAs($user)
        ->get(route('projects.create', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/create')
            ->where('tones', ['friendly', 'premium', 'playful', 'bold', 'minimal', 'professional'])
            ->has('creationModes', 3)
            ->where('creationModes.0.available', true)
            ->where('creationModes.1.available', false)
            ->has('languages'));
});

test('any member can create a project', function () {
    [$user, $team] = member(TeamRole::Member);

    $response = $this->actingAs($user)
        ->post(route('projects.store', ['current_team' => $team->slug]), validProjectPayload());

    $project = Project::sole();
    $response->assertRedirect(route('projects.show', ['current_team' => $team->slug, 'project' => $project->id]));

    expect($project->team_id)->toBe($team->id)
        ->and($project->created_by)->toBe($user->id)
        ->and($project->status->value)->toBe('draft')
        ->and($project->currency)->toBe('USD')
        ->and($project->direction->value)->toBe('ltr')
        ->and($project->brief['brand_colors'])->toBe(['#7c4a1e'])
        ->and(strlen($project->id))->toBe(26);
});

test('an Arabic project is right-to-left', function () {
    [$user, $team] = member();

    $this->actingAs($user)
        ->post(route('projects.store', ['current_team' => $team->slug]), validProjectPayload(['language' => 'ar']));

    expect(Project::sole()->direction->value)->toBe('rtl');
});

test('HTML is stripped from text inputs', function () {
    [$user, $team] = member();

    $this->actingAs($user)->post(route('projects.store', ['current_team' => $team->slug]), validProjectPayload([
        'name' => '<b>Clay</b> & Co',
        'brief' => ['niche' => 'Mugs<script>alert(1)</script>'],
    ]));

    $project = Project::sole();

    expect($project->name)->toBe('Clay & Co')
        ->and($project->brief['niche'])->toBe('Mugsalert(1)');
});

test('project input is validated', function (array $overrides, string $errorKey) {
    [$user, $team] = member();

    $this->actingAs($user)
        ->post(route('projects.store', ['current_team' => $team->slug]), validProjectPayload($overrides))
        ->assertSessionHasErrors($errorKey);

    expect(Project::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'unsupported language' => [['language' => 'xx'], 'language'],
    'bad tone' => [['brief' => ['tone' => 'angry']], 'brief.tone'],
    'short niche' => [['brief' => ['niche' => 'ab']], 'brief.niche'],
    'bad colour' => [['brief' => ['brand_colors' => ['red']]], 'brief.brand_colors.0'],
    'too many colours' => [['brief' => ['brand_colors' => ['#000000', '#111111', '#222222', '#333333']]], 'brief.brand_colors'],
    'import mode not available yet' => [['creation_mode' => 'import_design'], 'creation_mode'],
    'bad currency' => [['currency' => 'dollars'], 'currency'],
]);

test('unknown brief keys are rejected', function () {
    [$user, $team] = member();

    $this->actingAs($user)
        ->post(route('projects.store', ['current_team' => $team->slug]), validProjectPayload(['brief' => ['secret' => 'x']]))
        ->assertSessionHasErrors('brief');
});

test('members can view a project of their team', function () {
    [$user, $team] = member(TeamRole::Member);
    $project = Project::factory()->for($team)->create();

    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.id', $project->id)
            ->where('can.update', true)
            ->where('can.delete', false));
});

test('a project cannot be reached through another team URL', function () {
    [$user, $team] = member();
    [, $otherTeam] = member(TeamRole::Owner);
    $otherProject = Project::factory()->for($otherTeam)->create();

    // Under the user's own team slug the foreign project does not exist...
    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => $otherProject->id]))
        ->assertNotFound();

    // ...and the other team's URL is forbidden to non-members.
    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $otherTeam->slug, 'project' => $otherProject->id]))
        ->assertForbidden();
});

test('members can update a project and the revision increments', function () {
    [$user, $team] = member(TeamRole::Member);
    $project = Project::factory()->for($team)->create();

    $this->actingAs($user)
        ->put(route('projects.update', ['current_team' => $team->slug, 'project' => $project->id]), validProjectPayload(['name' => 'Renamed']))
        ->assertRedirect(route('projects.show', ['current_team' => $team->slug, 'project' => $project->id]));

    expect($project->fresh()->name)->toBe('Renamed')
        ->and($project->fresh()->revision)->toBe(1);
});

test('members cannot delete projects', function () {
    [$user, $team] = member(TeamRole::Member);
    $project = Project::factory()->for($team)->create();

    $this->actingAs($user)
        ->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $project->id]))
        ->assertForbidden();

    expect($project->fresh()->trashed())->toBeFalse();
});

test('admins and owners can delete projects (soft delete)', function (TeamRole $role) {
    [$user, $team] = member($role);
    $project = Project::factory()->for($team)->create();

    $this->actingAs($user)
        ->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $project->id]))
        ->assertRedirect(route('projects.index', ['current_team' => $team->slug]));

    expect($project->fresh()->trashed())->toBeTrue();
})->with([TeamRole::Admin, TeamRole::Owner]);

test('the current team credit balance is shared with every page', function () {
    [$user, $team] = member();
    $team->forceFill(['credit_balance' => 42])->save();

    $this->actingAs($user)
        ->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('credits', 42));
});
