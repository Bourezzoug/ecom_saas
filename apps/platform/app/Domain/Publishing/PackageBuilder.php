<?php

namespace App\Domain\Publishing;

use Aisg\Sections\FieldTypes;
use App\Domain\Sections\SectionLibrary;
use App\Enums\PageKind;
use App\Models\Asset;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;

/**
 * Builds the target-neutral publish package (docs/ARCHITECTURE.md §4.8).
 * The platform never builds Elementor data: the plugin turns this into
 * containers + widgets, so a future Shopify renderer consumes the same package.
 *
 * Media: every image (section fields, products, categories) is listed once in
 * "assets" with a stable ref; content keeps {asset_id, url, alt} and the plugin
 * swaps in its attachment. URLs point at the platform as seen by the site.
 */
class PackageBuilder
{
    public const VERSION = 1;

    /** @var array<string, array<string, mixed>> */
    private array $assets = [];

    public function __construct(private readonly SectionLibrary $sections) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Project $project): array
    {
        $this->assets = [];
        $project->load(['designTokens', 'pages.sections', 'categories', 'products.categories:id']);

        $pages = $project->pages->where('kind', PageKind::Page)->sortBy('position')->values();

        $package = [
            'package_version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'project' => [
                'ref' => $project->id,
                'name' => $project->name,
                'tagline' => (string) ($project->site_plan['tagline'] ?? ''),
                'language' => $project->language,
                'direction' => $project->direction->value,
                'currency' => $project->currency,
            ],
            'sections' => collect($this->sections->registry()->all())->map(fn ($d) => $d->version)->all(),
            'design_tokens' => $project->designTokens?->toTokens(),
            'categories' => $this->categories($project),
            'products' => $project->products->map(fn (Product $p) => $this->product($p))->values()->all(),
            'layout_parts' => $project->pages
                ->filter(fn (Page $p) => $p->kind !== PageKind::Page)
                ->map(fn (Page $p) => [
                    'ref' => $p->id,
                    'kind' => $p->kind->value,
                    'sections' => $this->sectionsOf($p),
                ])->values()->all(),
            'pages' => $pages->map(fn (Page $p) => [
                'ref' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'type' => $p->type->value,
                'is_homepage' => $p->is_homepage,
                'position' => $p->position,
                'seo' => (object) $p->seo,
                'sections' => $this->sectionsOf($p),
            ])->all(),
            'menus' => [[
                'location' => 'primary',
                'items' => [
                    ...$pages->map(fn (Page $p) => ['label' => $p->title, 'target' => ['kind' => 'page', 'ref' => $p->id]])->all(),
                    ...($project->products->isNotEmpty() ? [['label' => __('Shop'), 'target' => ['kind' => 'system', 'ref' => 'shop']]] : []),
                ],
            ]],
        ];

        $package['assets'] = $this->collectedAssets();

        return $package;
    }

    /**
     * Parents before children so the plugin can resolve parent refs in one pass.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(Project $project): array
    {
        $all = $project->categories->keyBy('id');
        $ordered = [];
        $visit = function (ProductCategory $c) use (&$visit, &$ordered, $all) {
            if (isset($ordered[$c->id])) {
                return;
            }
            if ($c->parent_id && $all->has($c->parent_id)) {
                $visit($all->get($c->parent_id));
            }
            $ordered[$c->id] = [
                'ref' => $c->id,
                'parent_ref' => $c->parent_id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => (string) $c->description,
                'image_ref' => $c->image ? $this->asset($c->image) : null,
            ];
        };
        $all->each($visit);

        return array_values($ordered);
    }

    /**
     * @return array<string, mixed>
     */
    private function product(Product $product): array
    {
        return [
            'ref' => $product->id,
            'type' => $product->type,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'regular_price' => $product->regular_price,
            'sale_price' => $product->sale_price,
            'short_description' => (string) $product->short_description,
            'description' => (string) $product->description,
            'status' => $product->status,
            'featured' => $product->featured,
            'stock_status' => $product->stock_status,
            'stock_quantity' => $product->stock_quantity,
            'category_refs' => $product->categories->pluck('id')->all(),
            'image_refs' => array_values(array_filter(array_map(fn ($i) => $this->asset($i), $product->images))),
            'attributes' => $product->attributes,
            'variations' => array_map(fn (array $v) => [
                ...$v,
                'image_ref' => ! empty($v['image']) ? $this->asset(['url' => $v['image'], 'alt' => $product->name]) : null,
            ], $product->variations),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sectionsOf(Page $page): array
    {
        return $page->sections->sortBy('position')->map(function (PageSection $s) {
            $content = $s->content;

            if ($this->sections->registry()->has($s->section_key)) {
                $content = $this->registerImages($this->sections->get($s->section_key)->fields(), $content);
            }

            return [
                'ref' => $s->id,
                'key' => $s->section_key,
                'version' => $s->section_version,
                'content' => (object) $content,
                'style' => (object) $s->style,
            ];
        })->values()->all();
    }

    /**
     * Register every image of a section in the asset list; rewrite its URL.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function registerImages(array $fields, array $content): array
    {
        foreach ($fields as $field) {
            $name = $field['name'];

            if ($field['type'] === FieldTypes::IMAGE && ! empty($content[$name]['url'])) {
                $ref = $this->asset($content[$name]);
                $content[$name] = [...$content[$name], 'asset_id' => $ref, 'url' => $this->assets[$ref]['url']];
            }

            if ($field['type'] === FieldTypes::REPEATER && is_array($content[$name] ?? null)) {
                $content[$name] = array_map(fn ($item) => is_array($item) ? $this->registerImages($field['fields'], $item) : $item, $content[$name]);
            }
        }

        return $content;
    }

    /**
     * @param  array{asset_id?: string|null, url?: string|null, alt?: string}  $image
     */
    private function asset(array $image): ?string
    {
        $url = (string) ($image['url'] ?? '');

        if ($url === '') {
            return null;
        }

        $asset = ! empty($image['asset_id']) ? Asset::find($image['asset_id']) : null;
        $ref = $asset !== null ? $asset->id : 'url-'.substr(hash('sha256', $url), 0, 24);

        $this->assets[$ref] ??= [
            'ref' => $ref,
            'url' => $asset ? $this->publicUrl($asset->url()) : $url,
            'checksum' => $asset?->checksum,
            'mime' => $asset?->mime,
            'alt' => (string) ($image['alt'] ?? ''),
            'file' => $asset ? $asset->path : null,
            'disk' => $asset?->disk,
        ];

        return $ref;
    }

    /**
     * Assets registered while building (filled by asset() during the build).
     *
     * @return list<array<string, mixed>>
     */
    private function collectedAssets(): array
    {
        return array_values($this->assets);
    }

    /**
     * Uploaded files as the WordPress site reaches them (platform_url instead of APP_URL).
     */
    private function publicUrl(string $url): string
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $platformUrl = (string) config('connector.platform_url');

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $platformUrl.$url; // relative disk URL
        }

        return str_starts_with($url, $appUrl) ? $platformUrl.substr($url, strlen($appUrl)) : $url;
    }
}
