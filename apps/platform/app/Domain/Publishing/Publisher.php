<?php

namespace App\Domain\Publishing;

use App\Domain\Editor\VersionRecorder;
use App\Domain\Publishing\Exceptions\ConnectorException;
use App\Enums\VersionReason;
use App\Events\PublishProgressed;
use App\Models\Publish;
use App\Models\PublishLog;
use App\Models\User;
use App\Models\WpConnection;
use App\Models\WpRemoteMapping;
use Throwable;

/**
 * Pushes a project to its connected WordPress site (docs/ARCHITECTURE.md §1.3):
 *
 *   health → snapshot → package → tokens → media → categories → products →
 *   header/footer → pages → menus → homepage → cache clear
 *
 * Every step is an idempotent upsert keyed by our refs, so a retry or a
 * re-publish updates instead of duplicating. Each item gets a publish_logs row.
 * Pages edited in WordPress since the last publish come back as conflicts
 * (skipped) unless the publish was started with "overwrite".
 */
class Publisher
{
    private Publish $publish;

    private WpConnection $connection;

    /** @var array{ok: int, failed: int, conflict: int} */
    private array $counts = ['ok' => 0, 'failed' => 0, 'conflict' => 0];

    public function __construct(
        private readonly ConnectorClient $client,
        private readonly ConnectionService $connections,
        private readonly PackageBuilder $packages,
        private readonly VersionRecorder $versions,
    ) {}

    public function run(Publish $publish): void
    {
        $this->publish = $publish;
        $this->connection = $publish->connection ?? throw new \RuntimeException('No connection.');
        $project = $publish->project;

        $publish->update(['status' => 'running', 'started_at' => now()]);
        $this->notify();

        $health = $this->connections->health($this->connection);

        if (! $health['ok']) {
            $this->finish('failed', 'The site did not answer the health check: '.$health['error']);

            return;
        }

        $version = $this->versions->record($project, VersionReason::PrePublish, $publish->triggered_by ? User::find($publish->triggered_by) : null);
        $publish->update(['project_version_id' => $version->id]);

        $package = $this->packages->build($project);
        $force = (bool) ($publish->options['force'] ?? false);

        try {
            $this->step('tokens', null, 'Design tokens', fn () => $this->client->call($this->connection, 'PUT', 'design-tokens', [
                'tokens' => $package['design_tokens'],
                'project' => $package['project'],
            ]));

            foreach (array_chunk($package['assets'], config('connector.batch.assets', 5)) as $chunk) {
                $this->batch('asset', 'assets', ['assets' => array_map(fn ($a) => array_diff_key($a, ['file' => 1, 'disk' => 1]), $chunk)], fn ($a) => $a['alt'] ?: basename(parse_url($a['url'], PHP_URL_PATH) ?: 'image'), $chunk);
            }

            foreach (array_chunk($package['categories'], config('connector.batch.categories', 50)) as $chunk) {
                $this->batch('category', 'categories', ['categories' => $chunk], fn ($c) => $c['name'], $chunk);
            }

            foreach (array_chunk($package['products'], config('connector.batch.products', 10)) as $chunk) {
                $this->batch('product', 'products', ['products' => $chunk], fn ($p) => $p['name'], $chunk);
            }

            foreach ($package['layout_parts'] as $part) {
                $this->step($part['kind'], $part['ref'], ucfirst($part['kind']), fn () => $this->client->call(
                    $this->connection, 'PUT', 'layout-parts/'.$part['kind'], ['part' => $part, 'project' => $package['project']],
                ));
            }

            foreach ($package['pages'] as $page) {
                $this->step('page', $page['ref'], $page['title'], fn () => $this->client->call(
                    $this->connection, 'PUT', 'pages/'.$page['ref'], ['page' => $page, 'force' => $force, 'project' => $package['project']],
                ));
            }

            foreach ($package['menus'] as $menu) {
                $this->step('menu', $menu['location'], 'Main menu', fn () => $this->client->call(
                    $this->connection, 'PUT', 'menus/'.$menu['location'], ['items' => $menu['items']],
                ));
            }

            $home = null;
            foreach ($package['pages'] as $candidate) {
                if (! empty($candidate['is_homepage'])) {
                    $home = $candidate;
                }
            }
            if ($home !== null) {
                $this->step('homepage', $home['ref'], 'Homepage setting', fn () => $this->client->call(
                    $this->connection, 'PUT', 'homepage', ['page_ref' => $home['ref']],
                ));
            }

            $this->step('cache', null, 'Clear Elementor cache', fn () => $this->client->call($this->connection, 'POST', 'cache/clear'));
        } catch (Throwable $e) {
            report($e);
            $this->finish('failed', $e->getMessage());

            return;
        }

        $status = $this->counts['failed'] + $this->counts['conflict'] === 0 ? 'succeeded' : 'partial';
        $this->finish($status, null);
    }

    /**
     * One item, one request.
     *
     * @param  callable(): array<string, mixed>  $call
     */
    private function step(string $type, ?string $ref, string $label, callable $call): void
    {
        $log = $this->log($type, $ref, $label);
        $started = hrtime(true);

        try {
            $result = $call();
            $this->complete($log, 'ok', $result['action'] ?? 'updated', $result['id'] ?? null, 200, null, $started);
            $this->map($type, $ref, $result['id'] ?? null, $result['hash'] ?? null);
        } catch (ConnectorException $e) {
            $this->complete($log, $e->isConflict() ? 'conflict' : 'failed', $e->isConflict() ? 'skipped' : null, null, $e->status, $e->getMessage(), $started);

            // Nothing below works without the site: stop on transport failures.
            if ($e->status === 0 || $e->status === 401 || $e->status === 403) {
                throw $e;
            }
        }
    }

    /**
     * One request for several items; the plugin reports per item.
     *
     * @param  array<string, mixed>  $body
     * @param  callable(array<string, mixed>): string  $label
     * @param  list<array<string, mixed>>  $items
     */
    private function batch(string $type, string $route, array $body, callable $label, array $items): void
    {
        $logs = [];
        foreach ($items as $item) {
            $logs[$item['ref']] = $this->log($type, $item['ref'], $label($item));
        }
        $started = hrtime(true);

        try {
            $response = $this->client->call($this->connection, 'PUT', $route, $body);
        } catch (ConnectorException $e) {
            foreach ($logs as $log) {
                $this->complete($log, 'failed', null, null, $e->status, $e->getMessage(), $started);
            }

            if ($e->status === 0 || $e->status === 401 || $e->status === 403) {
                throw $e;
            }

            return;
        }

        foreach ($response['results'] ?? [] as $result) {
            $log = $logs[$result['ref'] ?? ''] ?? null;

            if ($log === null) {
                continue;
            }

            $ok = ($result['status'] ?? 'ok') === 'ok';
            $this->complete($log, $ok ? 'ok' : 'failed', $result['action'] ?? null, $result['id'] ?? null, 200, $result['error'] ?? null, $started);

            if ($ok) {
                $this->map($type, $result['ref'], $result['id'] ?? null, null);
            }
        }

        foreach ($logs as $log) {
            if ($log->status === 'pending') {
                $this->complete($log, 'failed', null, null, 200, 'No result returned for this item.', $started);
            }
        }
    }

    private function log(string $type, ?string $ref, string $label): PublishLog
    {
        return PublishLog::create([
            'publish_id' => $this->publish->id,
            'item_type' => $type,
            'item_ref' => $ref,
            'label' => mb_substr($label, 0, 250),
            'status' => 'pending',
        ]);
    }

    private function complete(PublishLog $log, string $status, ?string $action, ?int $remoteId, ?int $http, ?string $error, int $started): void
    {
        $log->update([
            'status' => $status,
            'action' => $action,
            'remote_id' => $remoteId,
            'http_status' => $http,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        $key = $status === 'ok' ? 'ok' : ($status === 'conflict' ? 'conflict' : 'failed');
        $this->counts[$key]++;
        $this->notify();
    }

    private function map(string $type, ?string $ref, ?int $remoteId, ?string $hash): void
    {
        if ($ref === null || $remoteId === null) {
            return;
        }

        WpRemoteMapping::updateOrCreate(
            ['wp_connection_id' => $this->connection->id, 'local_type' => $type, 'local_ref' => $ref],
            ['remote_id' => $remoteId, 'published_hash' => $hash, 'published_at' => now()],
        );
    }

    private function finish(string $status, ?string $error): void
    {
        $this->publish->update([
            'status' => $status,
            'error' => $error,
            'summary' => $this->counts,
            'finished_at' => now(),
        ]);
        $this->notify();
    }

    private function notify(): void
    {
        rescue(fn () => PublishProgressed::dispatch($this->publish->project_id, $this->publish->id, $this->publish->status), report: false);
    }
}
