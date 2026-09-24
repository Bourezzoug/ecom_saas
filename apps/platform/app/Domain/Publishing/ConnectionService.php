<?php

namespace App\Domain\Publishing;

use App\Domain\Publishing\Exceptions\ConnectorException;
use App\Models\Project;
use App\Models\User;
use App\Models\WpConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connect flow (docs/ARCHITECTURE.md §7.3):
 *  1. create(): a pending connection + a Sanctum key shown to the user once
 *  2. the user pastes it in the plugin, which calls handshake() with its site info
 *  3. handshake() stores the site and returns a per-connection signing secret
 *  4. health() calls the plugin (signed) to confirm and record versions
 */
class ConnectionService
{
    public function __construct(private readonly ConnectorClient $client) {}

    /**
     * @return array{connection: WpConnection, key: string}
     */
    public function create(Project $project, User $user): array
    {
        return DB::transaction(function () use ($project, $user) {
            $project->hasMany(WpConnection::class)->where('status', '!=', 'revoked')->get()->each(fn (WpConnection $c) => $this->revoke($c));

            $connection = WpConnection::create([
                'project_id' => $project->id,
                'team_id' => $project->team_id,
                'created_by' => $user->id,
                'status' => 'pending',
            ]);

            $key = $connection->createToken('connector', ['connector'])->plainTextToken;
            $connection->update(['key_last_four' => substr($key, -4)]);

            return ['connection' => $connection, 'key' => $key];
        });
    }

    /**
     * @param  array{site_url: string, versions?: array<string, string|null>}  $site
     * @return array{connection_id: string, signing_secret: string, platform_url: string, project: string}
     */
    public function handshake(WpConnection $connection, array $site): array
    {
        $secret = bin2hex(random_bytes(32));

        $connection->update([
            'site_url' => rtrim($site['site_url'], '/'),
            'signing_secret' => $secret,
            'status' => 'connected',
            'connected_at' => now(),
            'last_error' => null,
            ...$this->versionColumns($site['versions'] ?? []),
        ]);

        return [
            'connection_id' => $connection->id,
            'signing_secret' => $secret,
            'platform_url' => config('connector.platform_url'),
            'project' => $connection->project->name,
        ];
    }

    /**
     * Signed health check; records versions and returns warnings for untested versions.
     *
     * @return array{ok: bool, health: array<string, mixed>, warnings: list<string>, error: string|null}
     */
    public function health(WpConnection $connection): array
    {
        try {
            $health = $this->client->call($connection, 'GET', 'health');
        } catch (ConnectorException $e) {
            $connection->update(['status' => $connection->status === 'revoked' ? 'revoked' : 'error', 'last_error' => $e->getMessage()]);

            return ['ok' => false, 'health' => [], 'warnings' => [], 'error' => $e->getMessage()];
        }

        $warnings = $this->warnings($health);

        $connection->update([
            'status' => 'connected',
            'last_health' => $health + ['warnings' => $warnings],
            'last_health_at' => now(),
            'last_error' => null,
            ...$this->versionColumns($health['versions'] ?? []),
        ]);

        return ['ok' => true, 'health' => $health, 'warnings' => $warnings, 'error' => null];
    }

    public function revoke(WpConnection $connection): void
    {
        $connection->tokens()->delete();
        $connection->update(['status' => 'revoked', 'revoked_at' => now(), 'signing_secret' => null]);
    }

    /**
     * @param  array<string, mixed>  $health
     * @return list<string>
     */
    public function warnings(array $health): array
    {
        $versions = $health['versions'] ?? [];
        $tested = config('connector.tested');
        $warnings = [];

        foreach (['elementor' => 'Elementor', 'woocommerce' => 'WooCommerce', 'wordpress' => 'WordPress'] as $key => $label) {
            $version = $versions[$key] ?? null;

            if ($version === null) {
                $warnings[] = "{$label} is not active on the site.";
            } elseif (! Str::startsWith($version, $tested[$key])) {
                $warnings[] = "{$label} {$version} is untested (tested with {$tested[$key]}.x).";
            }
        }

        if (isset($versions['php']) && version_compare($versions['php'], $tested['php_min'], '<')) {
            $warnings[] = "PHP {$versions['php']} is too old (needs {$tested['php_min']}+).";
        }

        if (($health['elementor']['containers'] ?? true) === false) {
            $warnings[] = 'Elementor Flexbox Containers are disabled; the connector enables them on publish.';
        }

        if (isset($versions['plugin']) && version_compare($versions['plugin'], $tested['plugin'], '<')) {
            $warnings[] = "The AISG Connector plugin {$versions['plugin']} is outdated (latest {$tested['plugin']}).";
        }

        return $warnings;
    }

    /**
     * @param  array<string, string|null>  $versions
     * @return array<string, string|null>
     */
    private function versionColumns(array $versions): array
    {
        return array_filter([
            'wp_version' => $versions['wordpress'] ?? null,
            'php_version' => $versions['php'] ?? null,
            'elementor_version' => $versions['elementor'] ?? null,
            'woocommerce_version' => $versions['woocommerce'] ?? null,
            'plugin_version' => $versions['plugin'] ?? null,
            'theme' => $versions['theme'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
