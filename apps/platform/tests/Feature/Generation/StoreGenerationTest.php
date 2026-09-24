<?php

use App\Domain\Ai\AiManager;
use App\Domain\Ai\Exceptions\AiConnectionException;
use App\Domain\Credits\CreditLedger;
use App\Enums\PageKind;
use App\Enums\ProjectStatus;
use App\Enums\SectionStatus;
use App\Enums\TeamRole;
use App\Events\GenerationProgressed;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Product;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->artisan('sections:sync')->assertSuccessful();
    config(['credits.actions.generate_store' => 40]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    $this->user->switchTeam($this->team);
    app(CreditLedger::class)->grant($this->team, 100, 'test:grant');

    $this->project = Project::factory()->for($this->team)->create([
        'name' => 'Clay & Co',
        'brief' => ['niche' => 'Handmade mugs', 'audience' => 'Coffee lovers', 'tone' => 'friendly', 'style' => null, 'brand_colors' => ['#7c4a1e']],
    ]);

    $this->fake = app(AiManager::class)->fake();
    // Planner calls get a realistic plan; section calls fall through to schema-synthesised content.
    $this->fake->respondUsing(fn (array $call) => isset($call['schema']['properties']['pages']) ? validPlan() : null);
});

function validPlan(array $overrides = []): array
{
    return array_replace([
        'tagline' => 'Mugs made by hand',
        'design' => [
            'primary' => '#123456', 'secondary' => '#abcdef', 'accent' => '#ff9900', 'background' => '#ffffff', 'text' => '#111111',
            'heading_font' => 'Playfair Display', 'body_font' => 'Inter', 'radius' => 'lg', 'spacing' => 'airy',
        ],
        'pages' => [
            ['type' => 'about', 'title' => 'Our story', 'sections' => [['section' => 'hero-split', 'brief' => 'Tell the maker story']]],
            ['type' => 'home', 'title' => 'Home', 'sections' => [
                ['section' => 'hero-split', 'brief' => 'Introduce the mugs'],
                ['section' => 'product-grid', 'brief' => 'Best sellers'],
                ['section' => 'faq', 'brief' => 'Shipping and care'],
            ]],
            ['type' => 'faq', 'title' => 'Questions', 'sections' => [['section' => 'faq', 'brief' => 'All questions']]],
        ],
    ], $overrides);
}

function generateRoute(Project $project): string
{
    return route('projects.generate', ['current_team' => $project->team->slug, 'project' => $project->id]);
}

test('generating a store plans pages, writes every section and charges credits once', function () {
    $this->actingAs($this->user)->post(generateRoute($this->project))->assertRedirect();

    $project = $this->project->fresh();
    expect($project->status)->toBe(ProjectStatus::Ready)
        ->and($project->site_plan['tagline'])->toBe('Mugs made by hand');

    $pages = $project->pages()->with('sections')->get();
    $contentPages = $pages->where('kind', PageKind::Page)->values();

    // Home first; header and footer layout parts exist once.
    expect($contentPages->pluck('type')->map->value->all())->toBe(['home', 'about', 'faq'])
        ->and($contentPages->first()->is_homepage)->toBeTrue()
        ->and($contentPages->pluck('slug')->all())->toBe(['home', 'about', 'faq'])
        ->and($pages->where('kind', PageKind::Header)->count())->toBe(1)
        ->and($pages->where('kind', PageKind::Footer)->count())->toBe(1);

    $home = $contentPages->first();
    expect($home->sections->pluck('section_key')->all())->toBe(['hero-split', 'product-grid', 'faq'])
        ->and($home->sections->first()->brief)->toBe('Introduce the mugs');

    $sections = $project->sections()->get();
    expect($sections)->toHaveCount(7)
        ->and($sections->every(fn ($s) => $s->status === SectionStatus::Ready))->toBeTrue()
        ->and($home->sections->first()->content['headline'])->toStartWith('Sample');

    // Brand colour wins over the AI's primary; the planner's other choices are kept.
    $tokens = $project->designTokens;
    expect($tokens->colors['primary'])->toBe('#7c4a1e')
        ->and($tokens->colors['primary_contrast'])->toBe('#ffffff')
        ->and($tokens->fonts['heading']['family'])->toBe('Playfair Display')
        ->and($tokens->radius)->toBe('lg');

    // 1 planner call + 7 section calls + 1 sample catalog call, all under one root generation.
    $this->fake->assertCallCount(9);
    $root = Generation::whereNull('parent_id')->sole();
    expect($root->action_key)->toBe('generate_store')
        ->and($root->children()->count())->toBe(8)
        ->and($root->credits)->toBe(40);

    expect($this->team->fresh()->credit_balance)->toBe(60)
        ->and(CreditTransaction::where('type', 'spend')->count())->toBe(1)
        ->and(CreditTransaction::where('type', 'refund')->count())->toBe(0);
});

test('a store without products gets an AI sample catalog as drafts', function () {
    $this->actingAs($this->user)->post(generateRoute($this->project));

    $products = $this->project->products()->with('categories')->get();
    expect($products)->not->toBeEmpty()
        ->and($products->every(fn ($p) => $p->status === 'draft' && $p->source === 'ai'))->toBeTrue()
        ->and($this->project->categories()->count())->toBeGreaterThan(0)
        ->and($products->first()->categories)->not->toBeEmpty();
});

test('a store that already has products gets no sample catalog', function () {
    Product::factory()->for($this->project)->create();

    $this->actingAs($this->user)->post(generateRoute($this->project));

    $this->fake->assertCallCount(8);
    expect($this->project->products()->count())->toBe(1);
});

test('section prompts carry the store context, language and planner brief', function () {
    $this->project->update(['language' => 'ar', 'direction' => 'rtl']);

    $this->actingAs($this->user)->post(generateRoute($this->project));

    $this->fake->assertCalled(fn (array $call) => str_contains($call['prompt'], 'Goal of this section: Best sellers')
        && str_contains($call['prompt'], 'العربية')
        && str_contains($call['prompt'], 'Store: Clay & Co — Mugs made by hand')
        && $call['options']['task'] === 'copy');

    $this->fake->assertCalled(fn (array $call) => $call['options']['task'] === 'planner'
        && in_array('Cairo', $call['schema']['properties']['design']['properties']['heading_font']['enum'], true));
});

test('an invalid plan is repaired once using the rule errors', function () {
    $bad = validPlan(['pages' => [
        ['type' => 'home', 'title' => 'Home', 'sections' => [['section' => 'faq', 'brief' => 'x']]],
        ['type' => 'faq', 'title' => 'FAQ', 'sections' => [['section' => 'product-grid', 'brief' => 'wrong page']]],
        ['type' => 'faq', 'title' => 'FAQ 2', 'sections' => [['section' => 'faq', 'brief' => 'dup']]],
    ]]);
    $this->fake->push($bad);

    $this->actingAs($this->user)->post(generateRoute($this->project));

    $planner = Generation::whereNull('parent_id')->sole();
    expect($planner->status->value)->toBe('repaired')
        ->and($this->project->fresh()->status)->toBe(ProjectStatus::Ready);

    $repairPrompt = $this->fake->calls()[1]['prompt'];
    expect($repairPrompt)->toContain('"product-grid" is not allowed on a faq page')
        ->toContain('Page type "faq" is used 2 times');
});

test('a failed planner marks the project failed and refunds the credits', function () {
    $this->fake->push(new AiConnectionException('Ollama is down'));

    $this->actingAs($this->user)->post(generateRoute($this->project));

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Failed)
        ->and($this->project->pages()->count())->toBe(0)
        ->and($this->team->fresh()->credit_balance)->toBe(100);

    $refund = CreditTransaction::where('type', 'refund')->sole();
    expect($refund->amount)->toBe(40)
        ->and($refund->meta['reason'])->toContain('Ollama is down');
});

test('failed sections keep their defaults and the store is partial without refund', function () {
    $this->fake->respondUsing(function (array $call) {
        if (isset($call['schema']['properties']['pages'])) {
            return validPlan();
        }

        // Every FAQ section returns an invalid answer twice → fails after repair.
        return isset($call['schema']['properties']['items']) ? ['title' => 'x', 'intro' => '', 'items' => []] : null;
    });

    $this->actingAs($this->user)->post(generateRoute($this->project));

    $project = $this->project->fresh();
    expect($project->status)->toBe(ProjectStatus::Partial);

    $faqs = $project->sections()->where('section_key', 'faq')->get();
    expect($faqs)->toHaveCount(2)
        ->and($faqs->every(fn ($s) => $s->status === SectionStatus::Failed))->toBeTrue()
        ->and($faqs->first()->content['title'])->toBe('Frequently asked questions');

    expect($this->team->fresh()->credit_balance)->toBe(60);
});

test('when every section fails the action counts as failed and is refunded', function () {
    $this->fake->respondUsing(fn (array $call) => isset($call['schema']['properties']['pages'])
        ? validPlan()
        : new AiConnectionException('GPU on fire'));

    $this->actingAs($this->user)->post(generateRoute($this->project));

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Failed)
        ->and($this->team->fresh()->credit_balance)->toBe(100);
});

test('regenerating replaces the previous pages', function () {
    $this->actingAs($this->user)->post(generateRoute($this->project));
    $firstPageIds = $this->project->pages()->pluck('id');

    $this->actingAs($this->user)->post(generateRoute($this->project));

    expect($this->project->pages()->count())->toBe(5)
        ->and($this->project->pages()->whereIn('id', $firstPageIds)->count())->toBe(0)
        ->and($this->team->fresh()->credit_balance)->toBe(20);
});

test('generation is refused without enough credits and nothing is queued', function () {
    config(['credits.actions.generate_store' => 500]);

    $this->actingAs($this->user)
        ->post(generateRoute($this->project))
        ->assertSessionHasErrors(['generation' => 'Not enough credits: this needs 500, you have 100.']);

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and(Generation::count())->toBe(0);
    $this->fake->assertNothingCalled();
});

test('a project that is already generating cannot be started twice', function () {
    $this->project->update(['status' => ProjectStatus::Generating]);

    $this->actingAs($this->user)
        ->post(generateRoute($this->project))
        ->assertSessionHasErrors(['generation' => 'This store is already being generated.']);

    expect($this->team->fresh()->credit_balance)->toBe(100);
});

test('outsiders cannot generate someone else\'s project', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->post(generateRoute($this->project))->assertForbidden();

    $this->fake->assertNothingCalled();
});

test('the generate endpoint is rate limited', function () {
    $this->project->update(['status' => ProjectStatus::Generating]);

    foreach (range(1, 6) as $i) {
        $this->actingAs($this->user)->post(generateRoute($this->project));
    }

    $this->actingAs($this->user)->post(generateRoute($this->project))->assertTooManyRequests();
});

test('progress events are broadcast on the project channel', function () {
    Event::fake([GenerationProgressed::class]);

    $this->actingAs($this->user)->post(generateRoute($this->project));

    Event::assertDispatched(GenerationProgressed::class, fn ($e) => $e->stage === 'planning' && $e->broadcastOn()[0]->name === 'private-projects.'.$this->project->id);
    Event::assertDispatched(GenerationProgressed::class, fn ($e) => $e->sectionStatus === 'ready');
    Event::assertDispatched(GenerationProgressed::class, fn ($e) => $e->stage === 'done');
});

test('only team members may join the project channel', function () {
    $authorize = Broadcast::driver()->getChannels()->get('projects.{projectId}');

    expect($authorize($this->user, $this->project->id))->toBeTrue()
        ->and($authorize(User::factory()->create(), $this->project->id))->toBeFalse()
        ->and($authorize($this->user, 'missing'))->toBeFalse();
});

test('the project page exposes generation progress and cost', function () {
    $this->actingAs($this->user)->post(generateRoute($this->project));

    $this->actingAs($this->user)
        ->get(route('projects.show', ['current_team' => $this->team->slug, 'project' => $this->project->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generateCost', 40)
            ->where('generation.stage', 'done')
            ->where('generation.total', 7)
            ->where('generation.ready', 7)
            ->where('generation.pages.0.kind', 'header')
            ->where('generation.pages.1.title', 'Home')
            ->where('generation.pages.4.kind', 'footer'));
});

test('the preview renders the generated page with tokens, runtime and navigation', function () {
    $this->actingAs($this->user)->post(generateRoute($this->project));
    $about = $this->project->pages()->where('type', 'about')->first();

    $response = $this->actingAs($this->user)
        ->get(route('projects.preview', ['current_team' => $this->team->slug, 'project' => $this->project->id]))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    $html = $response->getContent();
    expect($html)
        ->toContain('<div class="aisg" dir="ltr" lang="en">')
        ->toContain('data-aisg-section="header-classic"')
        ->toContain('data-aisg-section="product-grid"')
        ->toContain('data-aisg-section="footer-columns"')
        ->toContain('--e-global-color-primary:#7c4a1e')
        ->toContain('family=Playfair+Display')
        ->toContain(route('sections.assets', ['file' => 'sections.css']))
        ->toContain(route('projects.preview', ['current_team' => $this->team->slug, 'project' => $this->project->id, 'page' => $about->id]))
        ->toContain(e($this->project->products()->first()->name));

    $this->actingAs($this->user)
        ->get(route('projects.preview', ['current_team' => $this->team->slug, 'project' => $this->project->id, 'page' => $about->id]))
        ->assertOk()
        ->assertSee('data-aisg-section="hero-split"', false)
        ->assertDontSee('data-aisg-section="product-grid"', false);
});

test('preview of a project without pages is a 404 and outsiders are blocked', function () {
    $this->actingAs($this->user)
        ->get(route('projects.preview', ['current_team' => $this->team->slug, 'project' => $this->project->id]))
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get(route('projects.preview', ['current_team' => $this->team->slug, 'project' => $this->project->id]))
        ->assertForbidden();
});

test('section assets serve only allowlisted files', function () {
    $this->get(route('sections.assets', ['file' => 'sections.css']))->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
    $this->get(route('sections.assets', ['file' => 'aisg.js']))->assertOk();
    $this->get('/section-assets/icons.json')->assertNotFound();
    $this->get('/section-assets/..env')->assertNotFound();
});
