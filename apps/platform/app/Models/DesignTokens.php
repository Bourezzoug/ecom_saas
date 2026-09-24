<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Global design settings of a project (1:1). Rendered as CSS variables.
 *
 * @property string $project_id
 * @property array{primary: string, primary_contrast: string, secondary: string, accent: string, background: string, surface: string, text: string, muted: string, border: string} $colors
 * @property array{heading: array{family: string}, body: array{family: string}} $fonts
 * @property string $radius
 * @property string $spacing
 * @property int $container_width
 * @property array<string, mixed> $extra
 */
#[Fillable(['colors', 'fonts', 'radius', 'spacing', 'container_width', 'extra'])]
class DesignTokens extends Model
{
    protected $table = 'design_tokens';

    protected $primaryKey = 'project_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'extra' => '{}',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The token payload shared by the preview renderer and the publish package.
     *
     * @return array<string, mixed>
     */
    public function toTokens(): array
    {
        return [
            'colors' => $this->colors,
            'fonts' => $this->fonts,
            'radius' => $this->radius,
            'spacing' => $this->spacing,
            'container_width' => $this->container_width,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'fonts' => 'array',
            'extra' => 'array',
            'container_width' => 'integer',
        ];
    }
}
