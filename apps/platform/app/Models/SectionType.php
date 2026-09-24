<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Database cache of a /sections package (written by `sections:sync`).
 *
 * @property string $key
 * @property int $version
 * @property string $name
 * @property string $category
 * @property string $placement
 * @property list<string> $page_types
 * @property bool $requires_woocommerce
 * @property array<string, mixed> $schema
 * @property array<string, mixed> $meta
 * @property array<string, mixed> $compiled
 * @property string $template_hash
 * @property bool $is_active
 * @property Carbon $synced_at
 */
class SectionType extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_types' => 'array',
            'requires_woocommerce' => 'boolean',
            'schema' => 'array',
            'meta' => 'array',
            'compiled' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
