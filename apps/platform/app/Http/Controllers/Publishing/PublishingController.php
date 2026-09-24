<?php

namespace App\Http\Controllers\Publishing;

use App\Domain\Publishing\ConnectionService;
use App\Domain\Publishing\Jobs\PublishProjectJob;
use App\Domain\Publishing\PluginPackager;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Publish;
use App\Models\PublishLog;
use App\Models\Team;
use App\Models\WpConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublishingController extends Controller
{
    public function __construct(private readonly ConnectionService $connections) {}

    public function show(Team $team, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/publish', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status->value],
            'connection' => fn () => $this->connectionSummary($this->activeConnection($project)),
            'newKey' => fn () => session('connectorKey'),
            'platformUrl' => config('connector.platform_url'),
            'publishes' => fn () => Publish::where('project_id', $project->id)->latest()->limit(10)->get()->map(fn (Publish $p) => [
                'id' => $p->id,
                'target' => $p->target,
                'status' => $p->status,
                'force' => (bool) ($p->options['force'] ?? false),
                'summary' => $p->summary,
                'error' => $p->error,
                'createdAt' => $p->created_at?->toIso8601String(),
                'finishedAt' => $p->finished_at?->toIso8601String(),
                'downloadUrl' => $p->export_asset_id ? route('publishing.download', ['current_team' => $team->slug, 'project' => $project->id, 'publish' => $p->id]) : null,
            ]),
            'logs' => fn () => ($latest = Publish::where('project_id', $project->id)->where('target', 'push')->latest()->first())
                ? $latest->logs()->get()->map(fn (PublishLog $l) => [
                    'id' => $l->id,
                    'type' => $l->item_type,
                    'label' => $l->label,
                    'status' => $l->status,
                    'action' => $l->action,
                    'error' => $l->error,
                    'remoteId' => $l->remote_id,
                ])
                : [],
        ]);
    }

    public function connect(Request $request, Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        ['key' => $key] = $this->connections->create($project, $request->user());

        // Shown exactly once.
        return back()->with('connectorKey', $key);
    }

    public function health(Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $connection = $this->activeConnection($project) ?? throw ValidationException::withMessages(['connection' => __('Connect a WordPress site first.')]);
        $result = $this->connections->health($connection);

        Inertia::flash('toast', $result['ok']
            ? ['type' => 'success', 'message' => __('The site is connected and healthy.')]
            : ['type' => 'error', 'message' => __('Health check failed: :error', ['error' => $result['error']])]);

        return back();
    }

    public function disconnect(Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        if ($connection = $this->activeConnection($project)) {
            $this->connections->revoke($connection);
        }

        return back();
    }

    public function publish(Request $request, Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['force' => ['boolean'], 'target' => ['required', 'in:push,zip']]);
        $connection = $this->activeConnection($project);

        if ($project->status === ProjectStatus::Generating) {
            throw ValidationException::withMessages(['publish' => __('Wait for the generation to finish.')]);
        }

        if ($project->pages()->doesntExist()) {
            throw ValidationException::withMessages(['publish' => __('Generate the store before publishing.')]);
        }

        if ($data['target'] === 'push' && ! $connection?->isConnected()) {
            throw ValidationException::withMessages(['publish' => __('Connect a WordPress site first.')]);
        }

        try {
            // Own savepoint: the unique "one running publish" index may reject the insert.
            $publish = DB::transaction(fn () => Publish::create([
                'project_id' => $project->id,
                'wp_connection_id' => $data['target'] === 'push' ? $connection->id : null,
                'triggered_by' => $request->user()->id,
                'target' => $data['target'],
                'status' => 'queued',
                'options' => ['force' => (bool) ($data['force'] ?? false)],
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['publish' => __('A publish is already running for this project.')]);
        }

        PublishProjectJob::dispatch($publish->id);

        return back();
    }

    public function download(Team $team, Project $project, string $publish): StreamedResponse
    {
        Gate::authorize('view', $project);

        $asset = Publish::where('project_id', $project->id)->findOrFail($publish)->export ?? abort(404);

        return Storage::disk($asset->disk)->download($asset->path, $asset->original_name);
    }

    /**
     * The installable plugin ZIP (built from the monorepo on demand).
     */
    public function plugin(Team $team, Project $project, PluginPackager $packager): BinaryFileResponse
    {
        Gate::authorize('view', $project);

        $path = storage_path('app/private/aisg-connector-'.Str::random(8).'.zip');

        return response()->download($packager->build($path), 'aisg-connector.zip')->deleteFileAfterSend();
    }

    private function activeConnection(Project $project): ?WpConnection
    {
        return WpConnection::where('project_id', $project->id)->where('status', '!=', 'revoked')->latest()->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function connectionSummary(?WpConnection $c): ?array
    {
        return $c ? [
            'id' => $c->id,
            'status' => $c->status,
            'siteUrl' => $c->site_url,
            'keyLastFour' => $c->key_last_four,
            'versions' => [
                'wordpress' => $c->wp_version,
                'php' => $c->php_version,
                'elementor' => $c->elementor_version,
                'woocommerce' => $c->woocommerce_version,
                'plugin' => $c->plugin_version,
                'theme' => $c->theme,
            ],
            'warnings' => $c->last_health['warnings'] ?? [],
            'lastHealthAt' => $c->last_health_at?->toIso8601String(),
            'lastError' => $c->last_error,
        ] : null;
    }
}
