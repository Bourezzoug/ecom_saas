<?php

namespace App\Domain\Preview;

use App\Models\Project;

/**
 * Neutral placeholder catalog used by previews while a project has no
 * products of its own. Same shapes as CatalogData (real products).
 */
class SampleCatalog
{
    private const PRICES = [24, 39, 18, 52, 29, 45, 33, 21, 60, 27, 35, 48];

    public function __construct(private readonly Project $project) {}

    /**
     * @return array{products: list<array<string, mixed>>, categories: list<array<string, mixed>>, sample: bool}
     */
    public function toArray(): array
    {
        return [
            'products' => $this->products(12),
            'categories' => $this->categories(8),
            'sample' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function products(int $limit): array
    {
        $limit = max(1, min(12, $limit));
        $formatter = new PriceFormatter($this->project);

        return array_map(fn (int $i) => [
            'id' => $i,
            'ref' => "sample-{$i}",
            'name' => __('Sample product :n', ['n' => $i]),
            'url' => '#',
            'image' => ['url' => '', 'alt' => ''],
            'gallery' => array_map(fn (int $g) => ['url' => '', 'alt' => __('Product image :n', ['n' => $g])], range(1, 4)),
            'attributes' => [
                ['name' => __('Size'), 'slug' => 'size', 'options' => [
                    ['value' => 's', 'label' => 'S'],
                    ['value' => 'm', 'label' => 'M'],
                    ['value' => 'l', 'label' => 'L'],
                ]],
            ],
            'price_html' => $formatter->html((string) self::PRICES[($i - 1) % count(self::PRICES)], null),
            'short_description' => '',
            'on_sale' => false,
            'featured' => true,
            'in_stock' => true,
            'category_refs' => ['sample-category-'.((($i - 1) % 4) + 1)],
            'add_to_cart_url' => '#',
        ], range(1, $limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(int $limit): array
    {
        $limit = max(1, min(8, $limit));

        return array_map(fn (int $i) => [
            'id' => $i,
            'ref' => "sample-category-{$i}",
            'name' => __('Category :n', ['n' => $i]),
            'url' => '#',
            'image' => ['url' => '', 'alt' => ''],
            'count' => 0,
        ], range(1, $limit));
    }
}
