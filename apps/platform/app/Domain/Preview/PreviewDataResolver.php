<?php

namespace App\Domain\Preview;

use Aisg\Sections\SectionDefinition;
use App\Enums\PageKind;
use App\Models\Page;
use App\Models\Project;
use Closure;
use Illuminate\Support\Collection;

/**
 * Platform-side implementation of the section data resolvers (schema.json "data").
 * Mirrors resolveData() in packages/renderer-js; the WordPress plugin resolves the
 * same keys from live WooCommerce data with the same shapes.
 */
class PreviewDataResolver
{
    /** @var Collection<int, Page>|null */
    private ?Collection $menuPages = null;

    /** @var array{products: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>, sample: bool} */
    private array $catalog;

    /**
     * @param  Closure(Page): string  $pageUrl
     */
    public function __construct(
        private readonly Project $project,
        private readonly Closure $pageUrl,
    ) {
        $this->catalog = CatalogData::for($project);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function resolve(SectionDefinition $definition, array $content): array
    {
        $data = [];

        foreach ($definition->data() as $key => $source) {
            $input = $content[$source['from_field'] ?? ''] ?? null;

            $data[$key] = match ($source['resolver']) {
                'menu' => $this->menu(),
                'products' => self::queryProducts($this->catalog['products'], is_array($input) ? $input : []),
                'product' => self::findProduct($this->catalog['products'], is_string($input) ? $input : null),
                'categories' => array_slice($this->catalog['categories'], 0, max(1, min(8, is_numeric($input) ? (int) $input : 4))),
                default => [],
            };
        }

        return $data;
    }

    /**
     * product_query → product cards: {source: latest|featured|on_sale|category|ids, limit, category, ids}.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public static function queryProducts(array $products, array $query): array
    {
        $source = $query['source'] ?? 'latest';

        $filtered = array_values(array_filter($products, fn (array $p) => match ($source) {
            'featured' => (bool) ($p['featured'] ?? false),
            'on_sale' => (bool) ($p['on_sale'] ?? false),
            'category' => in_array($query['category'] ?? null, $p['category_refs'] ?? [], true),
            'ids' => in_array($p['ref'], $query['ids'] ?? [], true),
            default => true,
        }));

        return array_slice($filtered, 0, max(1, min(24, (int) ($query['limit'] ?? 8))));
    }

    /**
     * The chosen product, or the first one when none is chosen (or it was deleted).
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    public static function findProduct(array $products, ?string $ref): array
    {
        foreach ($products as $product) {
            if ($ref !== null && $product['ref'] === $ref) {
                return $product;
            }
        }

        return $products[0] ?? [];
    }

    /**
     * @return array{items: array<int, array{label: string, href: string}>, home_href: string, cart_href: string}
     */
    public function menu(): array
    {
        $this->menuPages ??= $this->project->pages()->where('kind', PageKind::Page->value)->orderBy('position')->get();
        $home = $this->menuPages->firstWhere('is_homepage', true);

        return [
            'items' => $this->menuPages
                ->map(fn (Page $page) => ['label' => $page->title, 'href' => ($this->pageUrl)($page)])
                ->values()
                ->all(),
            'home_href' => $home ? ($this->pageUrl)($home) : '#',
            'cart_href' => '#',
        ];
    }
}
