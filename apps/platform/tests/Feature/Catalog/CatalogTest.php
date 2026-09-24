<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\WooCsvImporter;
use App\Domain\Preview\CatalogData;
use App\Domain\Preview\PreviewDataResolver;
use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => TeamRole::Member->value]);
    $this->user->switchTeam($this->team);
    $this->project = Project::factory()->for($this->team)->create(['currency' => 'EUR', 'language' => 'fr']);
    $this->args = ['current_team' => $this->team->slug, 'project' => $this->project->id];
});

test('the catalog page lists products and categories', function () {
    $product = Product::factory()->for($this->project)->create(['name' => 'Mug']);

    $this->actingAs($this->user)
        ->get(route('catalog.index', $this->args))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/catalog')
            ->where('products.0.id', $product->id)
            ->where('project.currency', 'EUR'));
});

test('products can be created with categories, and slugs stay unique per project', function () {
    $category = app(CatalogService::class)->saveCategory($this->project, 'Mugs');

    foreach ([1, 2] as $i) {
        $this->actingAs($this->user)->post(route('catalog.products.store', $this->args), [
            'name' => 'Clay <b>Mug</b>',
            'regular_price' => '24.50',
            'sale_price' => '19',
            'stock_status' => 'instock',
            'status' => 'publish',
            'images' => [['url' => 'https://cdn.example.com/mug.jpg', 'alt' => 'Mug']],
            'category_ids' => [$category->id],
        ])->assertSessionHasNoErrors();
    }

    $products = $this->project->products()->with('categories')->get();
    expect($products->pluck('slug')->all())->toBe(['clay-mug', 'clay-mug-2'])
        ->and($products[0]->name)->toBe('Clay Mug')
        ->and($products[0]->sale_price)->toBe('19.00')
        ->and($products[0]->categories->pluck('name')->all())->toBe(['Mugs']);
});

test('product input is validated', function (array $data, string $error) {
    $this->actingAs($this->user)
        ->post(route('catalog.products.store', $this->args), [
            'name' => 'Mug', 'stock_status' => 'instock', 'status' => 'publish', ...$data,
        ])
        ->assertSessionHasErrors($error);
})->with([
    'sale above regular' => [['regular_price' => 10, 'sale_price' => 12], 'sale_price'],
    'javascript image' => [['images' => [['url' => 'javascript:alert(1)']]], 'images.0.url'],
    'bad stock status' => [['stock_status' => 'maybe'], 'stock_status'],
    'missing name' => [['name' => ''], 'name'],
]);

test('categories of another project are ignored', function () {
    $foreign = app(CatalogService::class)->saveCategory(Project::factory()->create(), 'Foreign');

    $this->actingAs($this->user)->post(route('catalog.products.store', $this->args), [
        'name' => 'Mug', 'stock_status' => 'instock', 'status' => 'publish', 'category_ids' => [$foreign->id],
    ]);

    expect(Product::sole()->categories)->toBeEmpty();
});

test('the WooCommerce CSV import handles simple, variable, sale, categories and bad rows', function () {
    $result = app(WooCsvImporter::class)->import($this->project, base_path('tests/Fixtures/woo-products.csv'));

    expect($result['created'])->toBe(3)
        ->and($result['skipped'])->toBe(1)
        ->and($result['errors'][0])->toContain('unsupported product type "grouped"');

    $mug = $this->project->products()->where('sku', 'MUG-01')->first();
    expect($mug->featured)->toBeTrue()
        ->and($mug->stock_quantity)->toBe(12)
        ->and($mug->description)->toBe("Glazed by hand in small batches.\n\nDishwasher safe.")
        ->and($mug->categories->pluck('name')->all())->toBe(['Everyday']);

    $everyday = $this->project->categories()->where('name', 'Everyday')->first();
    expect($everyday->parent->name)->toBe('Mugs');

    $set = $this->project->products()->where('sku', 'SET-01')->first();
    expect($set->type)->toBe('variable')
        ->and($set->attributes)->toBe([['name' => 'Colour', 'options' => ['Sand', 'Charcoal'], 'variation' => true]])
        ->and(collect($set->variations)->pluck('attributes.Colour')->all())->toBe(['Sand', 'Charcoal'])
        ->and($set->variations[1]['stock_status'])->toBe('outofstock')
        ->and($set->variations[0]['regular_price'])->toBe('32.00');

    expect($this->project->products()->where('sku', 'MUG-02')->first()->sale_price)->toBe('19.00');
});

test('re-importing the same CSV updates instead of duplicating', function () {
    $importer = fn () => app(WooCsvImporter::class)->import($this->project, base_path('tests/Fixtures/woo-products.csv'));

    $importer();
    $second = $importer();

    expect($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(3)
        ->and($this->project->products()->count())->toBe(3)
        ->and(count($this->project->products()->where('sku', 'SET-01')->first()->variations))->toBe(2);
});

test('CSV upload through the page reports the result', function () {
    $this->actingAs($this->user)
        ->post(route('catalog.import', $this->args), ['file' => new UploadedFile(base_path('tests/Fixtures/woo-products.csv'), 'products.csv', 'text/csv', null, true)])
        ->assertSessionHas('importResult.created', 3);

    $this->actingAs($this->user)
        ->post(route('catalog.import', $this->args), ['file' => UploadedFile::fake()->createWithContent('x.csv', "foo\nbar")])
        ->assertSessionHasErrors('file');
});

test('publish all turns drafts into published products', function () {
    Product::factory()->for($this->project)->count(2)->create(['status' => 'draft', 'source' => 'ai']);

    $this->actingAs($this->user)->post(route('catalog.products.publish-all', $this->args));

    expect($this->project->products()->where('status', 'draft')->count())->toBe(0);
});

test('outsiders cannot touch the catalog', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('catalog.products.store', $this->args), ['name' => 'x'])
        ->assertForbidden();
});

test('previews use the real catalog once it has products', function () {
    expect(CatalogData::for($this->project)['sample'])->toBeTrue();

    $cat = app(CatalogService::class)->saveCategory($this->project, 'Cups');
    $a = app(CatalogService::class)->saveProduct($this->project, ['name' => 'A', 'regular_price' => '10.00', 'featured' => true, 'category_ids' => [$cat->id]]);
    $b = app(CatalogService::class)->saveProduct($this->project, ['name' => 'B', 'regular_price' => '20.00', 'sale_price' => '15.00']);

    $data = CatalogData::for($this->project);
    expect($data['sample'])->toBeFalse()
        ->and($data['products'])->toHaveCount(2)
        ->and($data['products'][1]['price_html'])->toContain('<del>')->toContain('<ins>');

    $names = fn (array $query) => array_column(PreviewDataResolver::queryProducts($data['products'], $query), 'name');
    expect($names(['source' => 'featured']))->toBe(['A'])
        ->and($names(['source' => 'on_sale']))->toBe(['B'])
        ->and($names(['source' => 'category', 'category' => $cat->id]))->toBe(['A'])
        ->and($names(['source' => 'latest', 'limit' => 1]))->toBe(['A'])
        ->and(PreviewDataResolver::findProduct($data['products'], $b->id)['name'])->toBe('B')
        ->and(PreviewDataResolver::findProduct($data['products'], 'missing')['name'])->toBe('A');
});
