<?php

namespace App\Models;

use App\Enums\PageKind;
use App\Enums\PageType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A page, or a layout part (header/footer), of a project. The ULID is the stable
 * ref used to map it to a WordPress page on (re-)publish.
 *
 * @property string $id
 * @property string $project_id
 * @property PageKind $kind
 * @property PageType $type
 * @property string $title
 * @property string $slug
 * @property int $position
 * @property bool $is_homepage
 * @property array<string, mixed> $seo
 * @property-read Project $project
 * @property-read Collection<int, PageSection> $sections
 */
#[Fillable(['kind', 'type', 'title', 'slug', 'position', 'is_homepage', 'seo'])]
class Page extends Model
{
    use HasUlids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'page',
        'type' => 'custom',
        'seo' => '{}',
        'is_homepage' => false,
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<PageSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('position');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PageKind::class,
            'type' => PageType::class,
            'is_homepage' => 'boolean',
            'position' => 'integer',
            'seo' => 'array',
        ];
    }
}
