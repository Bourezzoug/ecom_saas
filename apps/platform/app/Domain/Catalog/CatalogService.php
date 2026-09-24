<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes to a project's local catalog. Slugs are unique per project and
 * derived from names; categories are matched by name within their parent.
 */
class CatalogService
{
    /**
     * @param  array<string, mixed>  $data  Validated product attributes (+ optional "category_ids").
     */
    public function saveProduct(Project $project, array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($project, $data, $product) {
            $product ??= new Product(['position' => (int) $project->products()->max('position') + 1]);
            $categoryIds = $data['category_ids'] ?? null;
            unset($data['category_ids']);

            $product->fill($data);
            $product->project()->associate($project);

            if (! $product->exists || $product->isDirty('name') || empty($product->slug)) {
                $product->slug = $this->uniqueSlug(Product::class, $project, $data['slug'] ?? $product->name, $product->id);
            }

            $product->save();

            if (is_array($categoryIds)) {
                $valid = $project->categories()->whereIn('id', $categoryIds)->pluck('id');
                $product->categories()->sync($valid);
            }

            return $product->load('categories');
        });
    }

    public function saveCategory(Project $project, string $name, ?string $parentId = null, ?ProductCategory $category = null, ?string $description = null): ProductCategory
    {
        $category ??= new ProductCategory(['position' => (int) $project->categories()->max('position') + 1]);
        $category->fill(['name' => $name, 'parent_id' => $parentId, 'description' => $description]);
        $category->project()->associate($project);

        if (! $category->exists || $category->isDirty('name')) {
            $category->slug = $this->uniqueSlug(ProductCategory::class, $project, $name, $category->id);
        }

        $category->save();

        return $category;
    }

    /**
     * Find a category by name under a parent, creating it if needed.
     */
    public function findOrCreateCategory(Project $project, string $name, ?string $parentId = null): ProductCategory
    {
        $existing = $project->categories()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('parent_id', $parentId)
            ->first();

        return $existing ?? $this->saveCategory($project, $name, $parentId);
    }

    /**
     * "Clothing > Hoodies" → the leaf category, creating the path.
     */
    public function categoryPath(Project $project, string $path): ?ProductCategory
    {
        $parent = null;

        foreach (array_filter(array_map('trim', explode('>', $path))) as $name) {
            $parent = $this->findOrCreateCategory($project, mb_substr($name, 0, 120), $parent?->id);
        }

        return $parent;
    }

    /**
     * @param  class-string<Product|ProductCategory>  $model
     */
    private function uniqueSlug(string $model, Project $project, string $source, ?string $ignoreId): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(6));
        $base = mb_substr($base, 0, 180);
        $slug = $base;
        $n = 2;

        while ($model::query()
            ->where('project_id', $project->id)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
