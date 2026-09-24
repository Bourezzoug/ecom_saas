<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\WooCsvImporter;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The project's local product catalog (what gets pushed to WooCommerce).
 */
class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Team $team, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/catalog', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'currency' => $project->currency ?: 'USD'],
            'products' => fn () => $project->products()->with('categories:id,name')->get()->map(fn (Product $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'name' => $p->name,
                'sku' => $p->sku,
                'regularPrice' => $p->regular_price,
                'salePrice' => $p->sale_price,
                'shortDescription' => $p->short_description,
                'description' => $p->description,
                'images' => $p->images,
                'stockStatus' => $p->stock_status,
                'status' => $p->status,
                'source' => $p->source,
                'featured' => $p->featured,
                'variationCount' => count($p->variations),
                'categoryIds' => $p->categories->pluck('id'),
                'categoryNames' => $p->categories->pluck('name'),
            ]),
            'categories' => fn () => $project->categories()->withCount('products')->get()->map(fn (ProductCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'parentId' => $c->parent_id,
                'productCount' => $c->products_count,
            ]),
            'importResult' => fn () => session('importResult'),
        ]);
    }

    public function storeProduct(Request $request, Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $this->catalog->saveProduct($project, $this->productData($request, $project));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product added.')]);

        return back();
    }

    public function updateProduct(Request $request, Team $team, Project $project, string $product): RedirectResponse
    {
        Gate::authorize('update', $project);

        $model = $project->products()->findOrFail($product);
        $this->catalog->saveProduct($project, $this->productData($request, $project, $model), $model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product saved.')]);

        return back();
    }

    public function destroyProduct(Team $team, Project $project, string $product): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->products()->findOrFail($product)->delete();

        return back();
    }

    /**
     * Publish every draft (e.g. after checking the AI sample prices).
     */
    public function publishAll(Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $count = $project->products()->where('status', 'draft')->update(['status' => 'publish']);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count product published.|:count products published.', $count)]);

        return back();
    }

    public function storeCategory(Request $request, Team $team, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'string', Rule::exists('product_categories', 'id')->where('project_id', $project->id)],
        ]);

        $this->catalog->saveCategory($project, trim(strip_tags($data['name'])), $data['parent_id'] ?? null);

        return back();
    }

    public function updateCategory(Request $request, Team $team, Project $project, string $category): RedirectResponse
    {
        Gate::authorize('update', $project);

        $model = $project->categories()->findOrFail($category);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $this->catalog->saveCategory($project, trim(strip_tags($data['name'])), $model->parent_id, $model);

        return back();
    }

    public function destroyCategory(Team $team, Project $project, string $category): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->categories()->findOrFail($category)->delete();

        return back();
    }

    /**
     * WooCommerce CSV import (Products › Export format).
     */
    public function import(Request $request, Team $team, Project $project, WooCsvImporter $importer): RedirectResponse
    {
        Gate::authorize('update', $project);

        $request->validate([
            'file' => ['required', File::types(['csv', 'txt'])->max(5 * 1024)],
        ]);

        try {
            $result = $importer->import($project, $request->file('file')->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('importResult', $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(Request $request, Project $project, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->where('project_id', $project->id)->ignore($product?->id)],
            'regular_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lt:regular_price'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:10000'],
            'images' => ['array', 'max:10'],
            'images.*.url' => ['required', 'string', 'max:2048', 'regex:~^(https?://|/)~i'],
            'images.*.asset_id' => ['nullable', 'string', 'size:26'],
            'images.*.alt' => ['nullable', 'string', 'max:200'],
            'stock_status' => ['required', Rule::in(['instock', 'outofstock', 'onbackorder'])],
            'status' => ['required', Rule::in(['draft', 'publish'])],
            'featured' => ['boolean'],
            'category_ids' => ['array'],
            'category_ids.*' => ['string'],
        ]);

        return [
            ...$data,
            'name' => trim(strip_tags($data['name'])),
            'short_description' => strip_tags((string) ($data['short_description'] ?? '')),
            'description' => strip_tags((string) ($data['description'] ?? '')),
            'images' => array_map(fn ($i) => ['asset_id' => $i['asset_id'] ?? null, 'url' => $i['url'], 'alt' => (string) ($i['alt'] ?? '')], $data['images'] ?? []),
            'featured' => (bool) ($data['featured'] ?? false),
            'category_ids' => $data['category_ids'] ?? [],
        ];
    }
}
