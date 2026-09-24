<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A product in the project's local catalog (pushed to WooCommerce on publish).
 *
 * @property string $id
 * @property string $project_id
 * @property string $type simple|variable
 * @property string $name
 * @property string $slug
 * @property string|null $sku
 * @property string|null $regular_price
 * @property string|null $sale_price
 * @property string|null $short_description
 * @property string|null $description
 * @property list<array{asset_id?: string|null, url: string, alt?: string}> $images
 * @property list<array{name: string, options: list<string>, variation: bool}> $attributes
 * @property list<array<string, mixed>> $variations
 * @property string $stock_status
 * @property int|null $stock_quantity
 * @property string $status draft|publish
 * @property string $source manual|csv|ai
 * @property bool $featured
 * @property int $position
 * @property array<string, mixed> $extra
 * @property-read Collection<int, ProductCategory> $categories
 */
#[Fillable([
    'type', 'name', 'slug', 'sku', 'regular_price', 'sale_price', 'short_description', 'description',
    'images', 'attributes', 'variations', 'stock_status', 'stock_quantity', 'status', 'source', 'featured',
    'position', 'extra',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'simple',
        'images' => '[]',
        'attributes' => '[]',
        'variations' => '[]',
        'extra' => '{}',
        'stock_status' => 'instock',
        'status' => 'publish',
        'source' => 'manual',
        'featured' => false,
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsToMany<ProductCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'category_product', 'product_id', 'category_id');
    }

    public function isOnSale(): bool
    {
        return $this->sale_price !== null && $this->regular_price !== null
            && (float) $this->sale_price < (float) $this->regular_price;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'regular_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'images' => 'array',
            'attributes' => 'array',
            'variations' => 'array',
            'extra' => 'array',
            'featured' => 'boolean',
            'stock_quantity' => 'integer',
            'position' => 'integer',
        ];
    }
}
