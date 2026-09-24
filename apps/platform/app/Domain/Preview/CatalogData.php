<?php

namespace App\Domain\Preview;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * The catalog as previews see it: the project's real products and categories,
 * or SampleCatalog placeholders while it has none. The shapes are the section
 * data contract that the WordPress plugin fills from WooCommerce.
 */
final class CatalogData
{
    /**
     * @return array{products: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>, sample: bool}
     */
    public static function for(Project $project): array
    {
        // Drafts included: AI sample products start as drafts but should appear in previews.
        $products = $project->products()->with('categories:id')->get();

        if ($products->isEmpty()) {
            return (new SampleCatalog($project))->toArray();
        }

        $prices = new PriceFormatter($project);

        return [
            'products' => $products->map(fn (Product $p) => self::product($p, $prices))->values()->all(),
            'categories' => $project->categories()->get()->map(fn (ProductCategory $c) => [
                'id' => $c->id,
                'ref' => $c->id,
                'name' => $c->name,
                'url' => '#',
                'image' => ['url' => (string) ($c->image['url'] ?? ''), 'alt' => (string) ($c->image['alt'] ?? $c->name)],
                'count' => 0,
            ])->values()->all(),
            'sample' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function product(Product $product, PriceFormatter $prices): array
    {
        $images = array_values(array_filter($product->images, fn ($i) => ! empty($i['url'])));
        $first = $images[0] ?? ['url' => '', 'alt' => ''];

        return [
            'id' => $product->id,
            'ref' => $product->id,
            'name' => $product->name,
            'url' => '#',
            'image' => ['url' => (string) $first['url'], 'alt' => (string) ($first['alt'] ?? '') ?: $product->name],
            'gallery' => $images === []
                ? [['url' => '', 'alt' => $product->name]]
                : array_map(fn ($i) => ['url' => (string) $i['url'], 'alt' => (string) ($i['alt'] ?? '') ?: $product->name], $images),
            'attributes' => array_values(array_map(fn (array $a) => [
                'name' => $a['name'],
                'slug' => Str::slug($a['name']),
                'options' => array_map(fn (string $o) => ['value' => Str::slug($o), 'label' => $o], $a['options']),
            ], array_filter($product->attributes, fn ($a) => ! empty($a['variation'])))),
            'price_html' => $prices->html($product->regular_price, $product->sale_price),
            'short_description' => (string) $product->short_description,
            'on_sale' => $product->isOnSale(),
            'featured' => $product->featured,
            'in_stock' => $product->stock_status !== 'outofstock',
            'category_refs' => $product->categories->pluck('id')->all(),
            'add_to_cart_url' => '#',
        ];
    }
}
