<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Generation\SitePlanApplier;
use App\Domain\Publishing\ConnectionService;
use App\Domain\Publishing\PackageBuilder;
use App\Domain\Publishing\Publisher;
use App\Enums\ProjectStatus;
use App\Enums\TeamRole;
use App\Models\Asset;
use App\Models\PageSection;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Publish;
use App\Models\Team;
use App\Models\User;
use App\Models\WpConnection;
use App\Models\WpRemoteMapping;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->artisan('sections:sync')->assertSuccessful();
    config([
        'app.url' => 'http://localhost:8000',
        'connector.platform_url' => 'http://nginx',
        'connector.site_url_rewrites' => [],
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    $this->user->switchTeam($this->team);
    $this->project = Project::factory()->for($this->team)->create(['status' => ProjectStatus::Ready, 'site_plan' => ['tagline' => 'Mugs']]);
    $this->args = ['current_team' => $this->team->slug, 'project' => $this->project->id];

    app(SitePlanApplier::class)->apply($this->project, ['tagline' => 'Mugs', 'design' => ['primary' => '#123456'], 'pages' => [
        ['type' => 'home', 'title' => 'Home', 'sections' => [['section' => 'hero-split', 'brief' => 'x'], ['section' => 'product-grid', 'brief' => 'x']]],
        ['type' => 'about', 'title' => 'About', 'sections' => [['section' => 'lp-features', 'brief' => 'x']]],
    ]]);
    PageSection::query()->update(['status' => 'ready']);

    // A catalog with a parent/child category and an uploaded image.
    Storage::fake('public');
    Storage::disk('public')->put("projects/{$this->project->id}/mug.png", 'png-bytes');
    $this->asset = Asset::create(['team_id' => $this->team->id, 'project_id' => $this->project->id, 'kind' => 'product', 'disk' => 'public', 'path' => "projects/{$this->project->id}/mug.png", 'mime' => 'image/png', 'size' => 9, 'checksum' => str_repeat('a', 64)]);
    $catalog = app(CatalogService::class);
    $child = $catalog->categoryPath($this->project, 'Mugs > Everyday');
    $catalog->saveProduct($this->project, ['name' => 'Mug', 'regular_price' => '20.00', 'category_ids' => [$child->id], 'images' => [['asset_id' => $this->asset->id, 'url' => $this->asset->url(), 'alt' => 'Mug']]]);

    // Hero image uses the same asset (must be listed once).
    $hero = $this->project->pages()->where('type', 'home')->first()->sections()->first();
    $hero->update(['content' => [...$hero->content, 'image' => ['asset_id' => $this->asset->id, 'url' => $this->asset->url(), 'alt' => 'Hero']]]);

    ['connection' => $this->connection, 'key' => $key] = app(ConnectionService::class)->create($this->project, $this->user);
    $this->connection->update(['status' => 'connected', 'site_url' => 'http://wp.test', 'signing_secret' => 'secret']);
});

/**
 * A fake connector plugin: answers every signed route like the real one.
 *
 * @param  array<string, int>  $failRoutes  route prefix → HTTP status to return
 */
function fakePlugin(array $failRoutes = []): void
{
    Http::fake(function (Request $request) use ($failRoutes) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $route = (string) ($query['rest_route'] ?? '');

        foreach ($failRoutes as $prefix => $status) {
            if (str_starts_with($route, $prefix)) {
                return Http::response(['code' => 'aisg_conflict', 'message' => 'This page was edited in WordPress since the last publish.'], $status);
            }
        }

        $body = $request->data();
        $id = crc32($route) % 1000;

        return match (true) {
            $route === '/aisg/v1/health' => Http::response(healthBody()),
            in_array($route, ['/aisg/v1/assets', '/aisg/v1/categories', '/aisg/v1/products'], true) => Http::response(['results' => array_map(
                fn ($item) => ['ref' => $item['ref'], 'status' => 'ok', 'id' => crc32($item['ref']) % 1000, 'action' => 'created'],
                $body[trim(strrchr($route, '/'), '/')] ?? [],
            )]),
            default => Http::response(['id' => $id, 'action' => 'created', 'hash' => md5($route)]),
        };
    });
}

function publishNow(Project $project, WpConnection $connection, bool $force = false): Publish
{
    $publish = Publish::create(['project_id' => $project->id, 'wp_connection_id' => $connection->id, 'target' => 'push', 'status' => 'queued', 'options' => ['force' => $force]]);
    app(Publisher::class)->run($publish->fresh(['project', 'connection']));

    return $publish->fresh();
}

test('the package is target-neutral and complete', function () {
    $package = app(PackageBuilder::class)->build($this->project);

    expect($package['package_version'])->toBe(1)
        ->and($package['project']['tagline'])->toBe('Mugs')
        ->and(array_column($package['pages'], 'slug'))->toBe(['home', 'about'])
        ->and($package['pages'][0]['is_homepage'])->toBeTrue()
        ->and(array_column($package['layout_parts'], 'kind'))->toEqualCanonicalizing(['header', 'footer'])
        ->and(array_column($package['categories'], 'name'))->toBe(['Mugs', 'Everyday'])   // parent first
        ->and($package['categories'][1]['parent_ref'])->toBe($package['categories'][0]['ref'])
        ->and($package['products'][0]['category_refs'])->toBe([$package['categories'][1]['ref']])
        ->and($package['menus'][0]['items'][2])->toBe(['label' => 'Shop', 'target' => ['kind' => 'system', 'ref' => 'shop']])
        ->and($package['sections']['hero-split'])->toBe(1);

    // One asset entry, reachable from the site (platform_url instead of APP_URL).
    expect($package['assets'])->toHaveCount(1)
        ->and($package['assets'][0]['ref'])->toBe($this->asset->id)
        ->and($package['assets'][0]['url'])->toStartWith('http://nginx/storage/projects/')
        ->and($package['products'][0]['image_refs'])->toBe([$this->asset->id])
        ->and($package['pages'][0]['sections'][0]->content->image['url'] ?? (array) $package['pages'][0]['sections'][0]['content'])->not->toBeNull();

    // Sections carry content + style only: never Elementor data.
    expect(json_encode($package))->not->toContain('elType')->not->toContain('_elementor_data');
});

test('publishing pushes every item in dependency order and records mappings', function () {
    fakePlugin();

    $publish = publishNow($this->project, $this->connection);

    expect($publish->status)->toBe('succeeded')
        ->and($publish->summary)->toBe(['ok' => 12, 'failed' => 0, 'conflict' => 0]);

    $types = $publish->logs->pluck('item_type')->all();
    expect($types)->toBe(['tokens', 'asset', 'category', 'category', 'product', 'header', 'footer', 'page', 'page', 'menu', 'homepage', 'cache']);

    $routes = Http::recorded()->map(fn ($pair) => [$pair[0]->method(), urldecode(explode('rest_route=', $pair[0]->url())[1])])->all();
    expect($routes[0])->toBe(['GET', '/aisg/v1/health'])
        ->and($routes[1])->toBe(['PUT', '/aisg/v1/design-tokens'])
        ->and(end($routes))->toBe(['POST', '/aisg/v1/cache/clear']);

    expect(WpRemoteMapping::where('local_type', 'page')->count())->toBe(2)
        ->and(WpRemoteMapping::where('local_type', 'product')->count())->toBe(1)
        ->and(ProjectVersion::where('reason', 'pre_publish')->count())->toBe(1);

    // Every request is signed.
    Http::assertSent(fn (Request $r) => $r->hasHeader('X-AISG-Signature') && $r->header('X-AISG-Connection')[0] === $this->connection->id);
});

test('re-publishing sends the same refs (updates, never duplicates)', function () {
    fakePlugin();
    publishNow($this->project, $this->connection);
    $first = Http::recorded()->map(fn ($p) => $p[0]->url())->filter(fn ($u) => str_contains($u, 'pages'))->values();

    fakePlugin();
    publishNow($this->project, $this->connection);
    $second = Http::recorded()->map(fn ($p) => $p[0]->url())->filter(fn ($u) => str_contains($u, 'pages'))->values();

    expect($second->all())->toBe($first->all())
        ->and(WpRemoteMapping::where('local_type', 'page')->count())->toBe(2);
});

test('a page edited in WordPress is skipped as a conflict; the rest still publishes', function () {
    $about = $this->project->pages()->where('type', 'about')->first();
    fakePlugin(['/aisg/v1/pages/'.$about->id => 409]);

    $publish = publishNow($this->project, $this->connection);

    expect($publish->status)->toBe('partial')
        ->and($publish->summary['conflict'])->toBe(1)
        ->and($publish->logs->firstWhere('item_ref', $about->id)->status)->toBe('conflict')
        ->and($publish->logs->firstWhere('item_type', 'homepage')->status)->toBe('ok');
});

test('overwrite is passed to the plugin', function () {
    fakePlugin();
    publishNow($this->project, $this->connection, force: true);

    Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), '/aisg/v1/pages/') && $r['force'] === true);
});

test('an unreachable site fails the publish at the health check', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $publish = publishNow($this->project, $this->connection);

    expect($publish->status)->toBe('failed')
        ->and($publish->error)->toContain('health check')
        ->and($publish->logs)->toBeEmpty();
});

test('per-item batch failures are logged individually', function () {
    Http::fake(function (Request $request) {
        $route = urldecode(explode('rest_route=', $request->url())[1]);

        return match ($route) {
            '/aisg/v1/health' => Http::response(healthBody()),
            '/aisg/v1/products' => Http::response(['results' => [['ref' => $request['products'][0]['ref'], 'status' => 'failed', 'error' => 'SKU exists']]]),
            '/aisg/v1/assets', '/aisg/v1/categories' => Http::response(['results' => array_map(fn ($i) => ['ref' => $i['ref'], 'status' => 'ok', 'id' => 1], $request->data()[trim(strrchr($route, '/'), '/')])]),
            default => Http::response(['id' => 5, 'action' => 'updated']),
        };
    });

    $publish = publishNow($this->project, $this->connection);

    expect($publish->status)->toBe('partial')
        ->and($publish->logs->firstWhere('item_type', 'product')->error)->toBe('SKU exists');
});

test('the publish endpoint queues a run and refuses overlapping or impossible ones', function () {
    fakePlugin();

    $this->actingAs($this->user)->post(route('publishing.publish', $this->args), ['target' => 'push'])->assertSessionHasNoErrors();
    expect(Publish::where('target', 'push')->sole()->status)->toBe('succeeded'); // sync queue in tests

    Publish::create(['project_id' => $this->project->id, 'target' => 'push', 'status' => 'running']);
    $this->actingAs($this->user)->post(route('publishing.publish', $this->args), ['target' => 'push'])->assertSessionHasErrors('publish');

    Publish::query()->update(['status' => 'succeeded']);
    $this->connection->update(['status' => 'revoked']);
    $this->actingAs($this->user)->post(route('publishing.publish', $this->args), ['target' => 'push'])->assertSessionHasErrors('publish');
});

test('the publish page shows the connection, runs and latest log', function () {
    fakePlugin();
    publishNow($this->project, $this->connection);

    $this->actingAs($this->user)
        ->get(route('publishing.show', $this->args))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/publish')
            ->where('connection.status', 'connected')
            ->where('publishes.0.status', 'succeeded')
            ->has('logs', 12));
});

test('a ZIP export contains the plugin, the package and the media', function () {
    $this->actingAs($this->user)->post(route('publishing.publish', $this->args), ['target' => 'zip'])->assertSessionHasNoErrors();

    $publish = Publish::where('target', 'zip')->sole();
    expect($publish->status)->toBe('succeeded', (string) $publish->error);

    $path = Storage::disk('local')->path($publish->export->path);
    $zip = new ZipArchive;
    $zip->open($path);
    expect($zip->locateName('aisg-connector.zip'))->not->toBeFalse()
        ->and($zip->locateName('aisg-package.zip'))->not->toBeFalse()
        ->and($zip->getFromName('README.txt'))->toContain('Import package');

    $tmp = tempnam(sys_get_temp_dir(), 'pkg');
    file_put_contents($tmp, $zip->getFromName('aisg-package.zip'));
    $package = new ZipArchive;
    $package->open($tmp);
    $manifest = json_decode($package->getFromName('manifest.json'), true);

    expect($manifest['assets'][0]['file'])->toBe("media/{$this->asset->id}.png")
        ->and($package->getFromName($manifest['assets'][0]['file']))->toBe('png-bytes')
        ->and($manifest['assets'][0])->not->toHaveKey('disk');

    $this->actingAs($this->user)->get(route('publishing.download', [...$this->args, 'publish' => $publish->id]))->assertOk()->assertDownload();
    Storage::disk('local')->delete($publish->export->path);
});
