<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\GenerationTask;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Log of a single AI call (prompt, schema, raw + validated output, usage).
 *
 * @property string $id
 * @property string|null $parent_id
 * @property int $team_id
 * @property string|null $project_id
 * @property int|null $user_id
 * @property GenerationTask $task
 * @property string|null $action_key
 * @property string|null $provider
 * @property string|null $model
 * @property GenerationStatus $status
 * @property int $attempts
 * @property array<string, mixed>|null $json_schema
 * @property array<string, mixed>|null $output
 * @property string|null $raw_output
 * @property array<int, mixed>|null $validation_errors
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $duration_ms
 * @property int $credits
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable([
    'parent_id', 'team_id', 'project_id', 'user_id', 'task', 'action_key', 'provider', 'model', 'status',
    'attempts', 'system_prompt', 'prompt', 'json_schema', 'output', 'raw_output', 'validation_errors',
    'tokens_in', 'tokens_out', 'duration_ms', 'credits', 'error', 'started_at', 'finished_at',
])]
class Generation extends Model
{
    use HasUlids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'parent_id');
    }

    /**
     * @return HasMany<Generation, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Generation::class, 'parent_id');
    }

    /**
     * The record this generation produced content for (section, product, import...).
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => GenerationTask::class,
            'status' => GenerationStatus::class,
            'json_schema' => 'array',
            'output' => 'array',
            'validation_errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
