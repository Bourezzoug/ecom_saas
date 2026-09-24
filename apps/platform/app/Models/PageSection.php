<?php

namespace App\Models;

use App\Enums\SectionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section instance on a page: ordered, with content/style validated against
 * its section type's schema.
 *
 * @property string $id
 * @property string $page_id
 * @property string $section_key
 * @property int $section_version
 * @property int $position
 * @property array<string, mixed> $content
 * @property array<string, mixed> $style
 * @property string|null $brief
 * @property SectionStatus $status
 * @property string|null $last_generation_id
 * @property int $lock_version
 * @property-read Page $page
 */
#[Fillable(['section_key', 'section_version', 'position', 'content', 'style', 'brief', 'status', 'last_generation_id'])]
class PageSection extends Model
{
    use HasUlids;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'content' => '{}',
        'style' => '{}',
        'status' => 'pending',
        'lock_version' => 0,
    ];

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @return BelongsTo<SectionType, $this>
     */
    public function sectionType(): BelongsTo
    {
        return $this->belongsTo(SectionType::class, 'section_key', 'key');
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function lastGeneration(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'last_generation_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'style' => 'array',
            'status' => SectionStatus::class,
            'position' => 'integer',
            'section_version' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
