<?php

namespace App\Models;

use App\Enums\VersionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $project_id
 * @property int $number
 * @property VersionReason $reason
 * @property string|null $label
 * @property array<string, mixed> $snapshot
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read User|null $author
 */
class ProjectVersion extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => VersionReason::class,
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
