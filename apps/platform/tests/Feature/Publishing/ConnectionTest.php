<?php

use App\Domain\Publishing\ConnectionService;
use App\Domain\Publishing\RequestSigner;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\WpConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    $this->user->switchTeam($this->team);
    $this->project = Project::factory()->for($this->team)->create();
    $this->args = ['current_team' => $this->team->slug, 'project' => $this->project->id];
    config(['connector.site_url_rewrites' => ['http://localhost:8081' => 'http://wp']]);
});

function healthBody(array $versions = []): array
{
    return [
        'ok' => true,
        'versions' => [...['wordpress' => '7.1.2', 'php' => '8.3.1', 'elementor' => '4.3.1', 'woocommerce' => '11.1.2', 'plugin' => '0.2.0', 'theme' => 'hello-elementor 3.5.1'], ...$versions],
        'elementor' => ['active' => true, 'containers' => true],
    ];
}

function handshake(string $key): TestResponse
{
    return test()->withToken($key)->postJson('/api/connector/v1/handshake', [
        'site_url' => 'http://localhost:8081/',
        'versions' => ['wordpress' => '7.1.2', 'elementor' => '4.3.1'],
    ]);
}

test('connecting shows the key once and stores only its hash', function () {
    $this->actingAs($this->user)->post(route('publishing.connect', $this->args))->assertSessionHas('connectorKey');

    $key = session('connectorKey');
    $connection = WpConnection::sole();

    expect($connection->status)->toBe('pending')
        ->and($connection->key_last_four)->toBe(substr($key, -4))
        ->and(DB::table('personal_access_tokens')->value('token'))->not->toBe(explode('|', $key)[1]);
});

test('the plugin handshake returns a signing secret stored encrypted', function () {
    ['key' => $key] = app(ConnectionService::class)->create($this->project, $this->user);

    $response = handshake($key)->assertOk()->assertJsonPath('project', $this->project->name);

    $connection = WpConnection::sole();
    expect($connection->status)->toBe('connected')
        ->and($connection->site_url)->toBe('http://localhost:8081')
        ->and($connection->elementor_version)->toBe('4.3.1')
        ->and($connection->signing_secret)->toBe($response->json('signing_secret'))
        ->and(DB::table('wp_connections')->value('signing_secret'))->not->toBe($response->json('signing_secret'));
});

test('invalid or revoked keys are rejected', function () {
    handshake('1|not-a-real-token')->assertUnauthorized();

    ['connection' => $connection, 'key' => $key] = app(ConnectionService::class)->create($this->project, $this->user);
    app(ConnectionService::class)->revoke($connection);

    handshake($key)->assertUnauthorized();
});

test('a new key revokes the previous connection', function () {
    ['key' => $old] = app(ConnectionService::class)->create($this->project, $this->user);
    app(ConnectionService::class)->create($this->project, $this->user);

    handshake($old)->assertUnauthorized();
    expect(WpConnection::where('status', 'revoked')->count())->toBe(1);
});

test('the health check is signed, uses the dev URL rewrite and records versions', function () {
    ['connection' => $connection, 'key' => $key] = app(ConnectionService::class)->create($this->project, $this->user);
    handshake($key);
    $connection->refresh();

    Http::fake(['http://wp/*' => Http::response(healthBody())]);

    $result = app(ConnectionService::class)->health($connection);

    expect($result['ok'])->toBeTrue()->and($result['warnings'])->toBe([]);

    Http::assertSent(function (Request $request) use ($connection) {
        $expected = RequestSigner::sign(
            $connection->signing_secret,
            (int) $request->header('X-AISG-Timestamp')[0],
            $request->header('X-AISG-Nonce')[0],
            'GET',
            '/aisg/v1/health',
            '',
        );

        return str_starts_with($request->url(), 'http://wp/?rest_route=')
            && $request->header('X-AISG-Connection')[0] === $connection->id
            && hash_equals($expected, $request->header('X-AISG-Signature')[0]);
    });
});

test('untested versions produce warnings, failures mark the connection', function () {
    ['connection' => $connection, 'key' => $key] = app(ConnectionService::class)->create($this->project, $this->user);
    handshake($key);
    $connection->refresh();

    Http::fake(['*' => Http::sequence()
        ->push(healthBody(['elementor' => '3.20.0', 'woocommerce' => null, 'php' => '7.4.0']))
        ->push(['code' => 'rest_no_route'], 404)]);

    $warnings = app(ConnectionService::class)->health($connection)['warnings'];
    expect(implode(' | ', $warnings))
        ->toContain('Elementor 3.20.0 is untested')
        ->toContain('WooCommerce is not active')
        ->toContain('PHP 7.4.0 is too old');

    $failed = app(ConnectionService::class)->health($connection);
    expect($failed['ok'])->toBeFalse()
        ->and($failed['error'])->toBe('The AISG Connector plugin is not active on this site.')
        ->and($connection->fresh()->status)->toBe('error');
});

test('the signature scheme is stable (shared with the WordPress plugin)', function () {
    expect(RequestSigner::sign('secret', 1700000000, 'abc123', 'put', '/aisg/v1/pages/X', '{"a":1}'))
        ->toBe(hash_hmac('sha256', "1700000000\nabc123\nPUT\n/aisg/v1/pages/X\n".hash('sha256', '{"a":1}'), 'secret'));
});

test('the plugin can disconnect itself', function () {
    ['key' => $key] = app(ConnectionService::class)->create($this->project, $this->user);
    handshake($key);

    $this->withToken($key)->postJson('/api/connector/v1/disconnect')->assertOk();

    expect(WpConnection::sole()->status)->toBe('revoked')
        ->and(WpConnection::sole()->signing_secret)->toBeNull();
});

test('members of other teams cannot connect or publish', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->post(route('publishing.connect', $this->args))->assertForbidden();
    $this->actingAs($outsider)->post(route('publishing.publish', $this->args), ['target' => 'push'])->assertForbidden();
});
