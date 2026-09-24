<?php

namespace App\Models;

use App\Enums\CreationMode;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\TextDirection;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A generated store. The ULID is also the stable ref sent to WordPress.
 *
 * @property string $id
 * @property int $team_id
 * @property int|null $created_by
 * @property string $name
 * @property ProjectKind $kind
 * @property ProjectStatus $status
 * @property CreationMode $creation_mode
 * @property string $language
 * @property TextDirection $direction
 * @property string|null $currency
 * @property array<string, mixed> $brief
 * @property array<string, mixed>|null $site_plan
 * @property int $revision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read User|null $creator
 */
#[Fillable(['name', 'kind', 'status', 'creation_mode', 'language', 'direction', 'currency', 'brief'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'store',
        'status' => 'draft',
        'creation_mode' => 'describe',
        'direction' => 'ltr',
        'brief' => '{}',
        'revision' => 0,
    ];

    /**
     * Get the team that owns the project.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * All pages including layout parts (header/footer), in navigation order.
     *
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('position');
    }

    /**
     * Every section of every page and layout part.
     *
     * @return HasManyThrough<PageSection, Page, $this>
     */
    public function sections(): HasManyThrough
    {
        return $this->hasManyThrough(PageSection::class, Page::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('position')->orderBy('created_at');
    }

    /**
     * @return HasMany<ProductCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(ProductCategory::class)->orderBy('position')->orderBy('name');
    }

    /**
     * @return HasOne<DesignTokens, $this>
     */
    public function designTokens(): HasOne
    {
        return $this->hasOne(DesignTokens::class);
    }

    /**
     * Get the AI generations logged for this project.
     *
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProjectKind::class,
            'status' => ProjectStatus::class,
            'creation_mode' => CreationMode::class,
            'direction' => TextDirection::class,
            'brief' => 'array',
            'site_plan' => 'array',
            'revision' => 'integer',
        ];
    }
}
