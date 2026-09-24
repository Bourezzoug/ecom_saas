<?php

use App\Domain\Ai\AiManager;
use App\Domain\Ai\Exceptions\AiConnectionException;
use App\Domain\Credits\CreditLedger;
use App\Domain\Editor\VersionRecorder;
use App\Domain\Generation\SitePlanApplier;
use App\Enums\ProjectStatus;
use App\Enums\SectionStatus;
use App\Enums\TeamRole;
use App\Enums\VersionReason;
use App\Models\Asset;
use App\Models\CreditTransaction;
use App\Models\PageSection;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->artisan('sections:sync')->assertSuccessful();
    config(['credits.actions.regenerate_section' => 2]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($this->team);
    app(CreditLedger::class)->grant($this->team, 10, 'test:grant');

    $this->project = Project::factory()->for($this->team)->create(['status' => ProjectStatus::Ready]);

    app(SitePlanApplier::class)->apply($this->project, [
        'tagline' => 'Mugs made by hand',
        'design' => ['primary' => '#123456', 'background' => '#ffffff', 'text' => '#111111'],
        'pages' => [
            ['type' => 'home', 'title' => 'Home', 'sections' => [
                ['section' => 'hero-split', 'brief' => 'Intro'],
                ['section' => 'faq', 'brief' => 'Questions'],
            ]],
            ['type' => 'about', 'title' => 'About', 'sections' => [['section' => 'hero-split', 'brief' => 'Story']]],
        ],
    ]);
    PageSection::query()->update(['status' => 'ready']);

    $this->home = $this->project->pages()->where('type', 'home')->first();
    $this->hero = $this->home->sections()->first();
    $this->args = ['current_team' => $this->team->slug, 'project' => $this->project->id];
});

function editorUrl(string $name, array $args, array $extra = []): string
{
    return route("editor.{$name}", [...$args, ...$extra]);
}

test('the editor page ships the document, the whole library and sample data', function () {
    $this->actingAs($this->user)
        ->get(route('editor.show', $this->args))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/editor')
            ->has('library', 19)
            ->where('library.0.template', fn ($t) => str_contains($t, 'data-aisg-section'))
            ->has('document.pages', 4)
            ->where('document.tokens.colors.primary', '#7c4a1e')
            ->has('catalog.products', 12)
            ->has('catalog.products.0.gallery', 4)
            ->where('catalog.sample', true)
            ->where('regenerateCost', 2)
            ->has('fonts')
            ->has('icons'));
});

test('the editor redirects to the project until the store is generated', function () {
    $empty = Project::factory()->for($this->team)->create();

    $this->actingAs($this->user)
        ->get(route('editor.show', ['current_team' => $this->team->slug, 'project' => $empty->id]))
        ->assertRedirect(route('projects.show', ['current_team' => $this->team->slug, 'project' => $empty->id]));
});

test('outsiders cannot open or edit the project', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('editor.show', $this->args))->assertForbidden();
    $this->actingAs($outsider)
        ->patchJson(editorUrl('sections.update', $this->args, ['section' => $this->hero->id]), ['content' => [], 'lockVersion' => 0])
        ->assertForbidden();
});

test('a section edit is validated, saved and bumps the lock version and revision', function () {
    $content = [...$this->hero->content, 'headline' => 'Hand-thrown mugs for slow mornings'];

    $this->actingAs($this->user)
        ->patchJson(editorUrl('sections.update', $this->args, ['section' => $this->hero->id]), [
            'content' => $content,
            'style' => ['layout' => 'start', 'background' => 'primary'],
            'lockVersion' => 0,
        ])
        ->assertOk()
        ->assertJsonPath('section.lockVersion', 1)
        ->assertJsonPath('section.content.headline', 'Hand-thrown mugs for slow mornings');

    $hero = $this->hero->fresh();
    expect($hero->content['headline'])->toBe('Hand-thrown mugs for slow mornings')
        ->and($hero->style)->toBe(['layout' => 'start', 'background' => 'primary'])
        ->and($this->project->fresh()->revision)->toBeGreaterThan(0);
});

test('invalid content returns field errors keyed like the form', function () {
    $content = [...$this->hero->content, 'headline' => 'Hi', 'highlights' => [['icon' => 'rocket', 'text' => 'x']]];

    $this->actingAs($this->user)
        ->patchJson(editorUrl('sections.update', $this->args, ['section' => $this->hero->id]), ['content' => $content, 'lockVersion' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content.headline', 'content.highlights.0.icon']);

    expect($this->hero->fresh()->lock_version)->toBe(0);
});

test('a stale lock version is a 409 carrying the current section', function () {
    $this->hero->forceFill(['lock_version' => 3])->save();

    $this->actingAs($this->user)
        ->patchJson(editorUrl('sections.update', $this->args, ['section' => $this->hero->id]), ['content' => $this->hero->content, 'lockVersion' => 1])
        ->assertStatus(409)
        ->assertJsonPath('section.lockVersion', 3);
});

test('sections of another project are not reachable', function () {
    $other = Project::factory()->for($this->team)->create();
    app(SitePlanApplier::class)->apply($other, ['tagline' => 'x', 'design' => [], 'pages' => [
        ['type' => 'home', 'title' => 'Home', 'sections' => [['section' => 'faq', 'brief' => 'x']]],
    ]]);
    $foreign = $other->sections()->first();

    $this->actingAs($this->user)
        ->patchJson(editorUrl('sections.update', $this->args, ['section' => $foreign->id]), ['content' => $foreign->content, 'lockVersion' => 0])
        ->assertNotFound();
});

test('editing is paused while the store is being generated', function () {
    $this->project->update(['status' => ProjectStatus::Generating]);

    $this->actingAs($this->user)
        ->patchJson(editorUrl('sections.update', $this->args, ['section' => $this->hero->id]), ['content' => $this->hero->content, 'lockVersion' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project');
});

test('adding a section inserts it with defaults at the requested position', function () {
    $response = $this->actingAs($this->user)
        ->postJson(editorUrl('pages.sections.store', $this->args, ['page' => $this->home->id]), ['key' => 'testimonials', 'position' => 1])
        ->assertCreated()
        ->assertJsonPath('section.key', 'testimonials')
        ->assertJsonPath('section.content.items.0.name', 'Customer name');

    expect($this->home->sections()->pluck('section_key')->all())->toBe(['hero-split', 'testimonials', 'faq'])
        ->and($this->home->sections()->pluck('position')->all())->toBe([0, 1, 2]);
});

test('header, footer and unknown types cannot be added to pages', function (string $key) {
    $this->actingAs($this->user)
        ->postJson(editorUrl('pages.sections.store', $this->args, ['page' => $this->home->id]), ['key' => $key])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('key');
})->with(['header-classic', 'footer-columns', 'does-not-exist']);

test('sections can be removed and renumbered, but not the header', function () {
    $faq = $this->home->sections()->where('section_key', 'faq')->first();
    $header = $this->project->pages()->where('kind', 'header')->first()->sections()->first();

    $this->actingAs($this->user)->deleteJson(editorUrl('sections.destroy', $this->args, ['section' => $this->hero->id]))->assertOk();

    expect($this->home->sections()->count())->toBe(1)
        ->and($faq->fresh()->position)->toBe(0);

    $this->actingAs($this->user)
        ->deleteJson(editorUrl('sections.destroy', $this->args, ['section' => $header->id]))
        ->assertUnprocessable();
});

test('sections can be reordered with the complete list of ids', function () {
    $ids = $this->home->sections()->pluck('id')->all();

    $this->actingAs($this->user)
        ->putJson(editorUrl('pages.sections.reorder', $this->args, ['page' => $this->home->id]), ['ids' => array_reverse($ids)])
        ->assertOk();

    expect($this->home->sections()->pluck('section_key')->all())->toBe(['faq', 'hero-split']);

    $this->actingAs($this->user)
        ->putJson(editorUrl('pages.sections.reorder', $this->args, ['page' => $this->home->id]), ['ids' => [$ids[0]]])
        ->assertUnprocessable();
});

test('pages can be renamed', function () {
    $this->actingAs($this->user)
        ->patchJson(editorUrl('pages.update', $this->args, ['page' => $this->home->id]), ['title' => '<b>Welcome</b>'])
        ->assertOk()
        ->assertJsonPath('page.title', 'Welcome');
});

test('design edits keep the user\'s colours and re-derive the rest', function () {
    $this->actingAs($this->user)
        ->putJson(editorUrl('design.update', $this->args), [
            'colors' => ['primary' => '#fde68a', 'secondary' => '#000000', 'accent' => '#ff0000', 'background' => '#eeeeee', 'text' => '#cccccc'],
            'fonts' => ['heading' => 'Lora', 'body' => 'Inter'],
            'radius' => 'full',
            'spacing' => 'compact',
        ])
        ->assertOk()
        ->assertJsonPath('tokens.colors.text', '#cccccc')          // low contrast, but the user chose it
        ->assertJsonPath('tokens.colors.primary_contrast', '#111827')
        ->assertJsonPath('tokens.fonts.heading.family', 'Lora')
        ->assertJsonPath('tokens.radius', 'full');

    expect($this->project->designTokens->fresh()->colors['surface'])->not->toBeEmpty();
});

test('design edits reject fonts outside the language catalog', function () {
    $this->actingAs($this->user)
        ->putJson(editorUrl('design.update', $this->args), [
            'colors' => ['primary' => '#123456', 'secondary' => '#000000', 'accent' => '#ff0000', 'background' => '#ffffff', 'text' => '#111111'],
            'fonts' => ['heading' => 'Comic Sans MS', 'body' => 'Inter'],
            'radius' => 'md',
            'spacing' => 'normal',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fonts.heading');
});

test('edits take a throttled autosave snapshot', function () {
    $url = editorUrl('sections.update', $this->args, ['section' => $this->hero->id]);

    $this->actingAs($this->user)->patchJson($url, ['content' => $this->hero->content, 'lockVersion' => 0])->assertOk();
    $this->actingAs($this->user)->patchJson($url, ['content' => $this->hero->content, 'lockVersion' => 1])->assertOk();

    expect(ProjectVersion::where('reason', 'autosave')->count())->toBe(1);

    $this->travel(VersionRecorder::AUTOSAVE_MINUTES + 1)->minutes();
    $this->actingAs($this->user)->patchJson($url, ['content' => $this->hero->content, 'lockVersion' => 2])->assertOk();

    expect(ProjectVersion::where('reason', 'autosave')->count())->toBe(2);
});

test('a named version can be saved and an older version restored (undoable)', function () {
    $this->actingAs($this->user)
        ->postJson(editorUrl('versions.store', $this->args), ['label' => 'Before redesign'])
        ->assertCreated()
        ->assertJsonPath('versions.0.label', 'Before redesign');
    $saved = ProjectVersion::where('label', 'Before redesign')->sole();

    // Change something, then restore.
    $this->hero->forceFill(['content' => [...$this->hero->content, 'headline' => 'Changed headline']])->save();
    $this->home->sections()->where('section_key', 'faq')->delete();

    $this->actingAs($this->user)
        ->postJson(editorUrl('versions.restore', $this->args, ['version' => $saved->id]))
        ->assertOk()
        ->assertJsonPath('document.pages.1.sections.1.key', 'faq');

    $hero = PageSection::find($this->hero->id);   // ids survive a restore
    expect($hero->content['headline'])->not->toBe('Changed headline')
        ->and($this->home->sections()->count())->toBe(2)
        ->and(ProjectVersion::where('reason', 'pre_restore')->count())->toBe(1);
});

test('unnamed versions are pruned but named ones are kept', function () {
    $recorder = app(VersionRecorder::class);
    $recorder->record($this->project, VersionReason::Manual, $this->user, 'Keep me');

    foreach (range(1, VersionRecorder::KEEP_UNNAMED + 5) as $i) {
        $recorder->record($this->project, VersionReason::Autosave);
    }

    expect(ProjectVersion::whereNull('label')->count())->toBe(VersionRecorder::KEEP_UNNAMED)
        ->and(ProjectVersion::where('label', 'Keep me')->exists())->toBeTrue();
});

test('regenerating a section charges credits, snapshots first and uses the instruction', function () {
    $fake = app(AiManager::class)->fake();

    $this->actingAs($this->user)
        ->postJson(editorUrl('sections.regenerate', $this->args, ['section' => $this->hero->id]), ['instruction' => 'more playful'])
        ->assertStatus(202);

    $fake->assertCalled(fn (array $call) => str_contains($call['prompt'], 'Extra instruction from the user: more playful'));

    $hero = $this->hero->fresh();
    expect($hero->status)->toBe(SectionStatus::Ready)
        ->and($hero->content['headline'])->toStartWith('Sample')
        ->and($hero->lock_version)->toBe(1)
        ->and($this->team->fresh()->credit_balance)->toBe(8)
        ->and(ProjectVersion::where('reason', 'pre_regenerate')->count())->toBe(1);
});

test('a failed rewrite keeps the text and refunds the credits', function () {
    app(AiManager::class)->fake()->push(new AiConnectionException('down'));
    $before = $this->hero->content;

    $this->actingAs($this->user)
        ->postJson(editorUrl('sections.regenerate', $this->args, ['section' => $this->hero->id]))
        ->assertStatus(202);

    expect($this->hero->fresh()->content)->toBe($before)
        ->and($this->hero->fresh()->status)->toBe(SectionStatus::Ready)
        ->and($this->team->fresh()->credit_balance)->toBe(10)
        ->and(CreditTransaction::where('type', 'refund')->count())->toBe(1);
});

test('rewrites need credits and cannot overlap', function () {
    app(AiManager::class)->fake();
    config(['credits.actions.regenerate_section' => 50]);

    $this->actingAs($this->user)
        ->postJson(editorUrl('sections.regenerate', $this->args, ['section' => $this->hero->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('regenerate');

    config(['credits.actions.regenerate_section' => 2]);
    $this->hero->forceFill(['status' => SectionStatus::Generating])->save();

    $this->actingAs($this->user)
        ->postJson(editorUrl('sections.regenerate', $this->args, ['section' => $this->hero->id]))
        ->assertUnprocessable();
});

test('images are uploaded to the project folder and validated by content', function () {
    Storage::fake('public');

    $response = $this->actingAs($this->user)
        ->post(editorUrl('assets.store', $this->args), ['file' => UploadedFile::fake()->image('mug.jpg', 1200, 800)], ['Accept' => 'application/json'])
        ->assertCreated();

    $asset = Asset::sole();
    expect($asset->project_id)->toBe($this->project->id)
        ->and($asset->width)->toBe(1200)
        ->and($asset->checksum)->toHaveLength(64)
        ->and($response->json('asset.url'))->toContain("projects/{$this->project->id}/");
    Storage::disk('public')->assertExists($asset->path);

    $this->actingAs($this->user)
        ->post(editorUrl('assets.store', $this->args), ['file' => UploadedFile::fake()->create('evil.php.jpg', 10, 'application/x-php')], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->post(editorUrl('assets.store', $this->args), ['file' => UploadedFile::fake()->image('huge.jpg')->size(9000)], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

test('finishing a store generation records a restore point', function () {
    app(AiManager::class)->fake()->respondUsing(fn (array $call) => isset($call['schema']['properties']['pages']) ? [
        'tagline' => 't', 'design' => ['primary' => '#123456'],
        'pages' => [
            ['type' => 'home', 'title' => 'Home', 'sections' => [['section' => 'faq', 'brief' => 'x']]],
            ['type' => 'about', 'title' => 'About', 'sections' => [['section' => 'faq', 'brief' => 'x']]],
            ['type' => 'faq', 'title' => 'FAQ', 'sections' => [['section' => 'faq', 'brief' => 'x']]],
        ],
    ] : null);
    config(['credits.actions.generate_store' => 5]);

    $this->actingAs($this->user)->post(route('projects.generate', $this->args));

    expect(ProjectVersion::where('reason', 'generation')->count())->toBe(1);
});
