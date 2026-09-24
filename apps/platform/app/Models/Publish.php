<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One publish run: a push to a connected site, or a ZIP export.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $wp_connection_id
 * @property string $target push|zip
 * @property string $status queued|running|succeeded|partial|failed
 * @property array<string, mixed> $options
 * @property array<string, mixed> $summary
 * @property string|null $export_asset_id
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read Project $project
 * @property-read WpConnection|null $connection
 */
class Publish extends Model
{
    use HasUlids;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'options' => '{}',
        'summary' => '{}',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<WpConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(WpConnection::class, 'wp_connection_id');
    }

    /**
     * @return HasMany<PublishLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PublishLog::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function export(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'export_asset_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
