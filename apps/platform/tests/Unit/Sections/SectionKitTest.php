<?php

use Aisg\Sections\DesignTokensCss;
use Aisg\Sections\Exceptions\InvalidSectionException;
use Aisg\Sections\Icons;
use Aisg\Sections\RenderContext;
use Aisg\Sections\Renderer;
use Aisg\Sections\SchemaCompiler;
use Aisg\Sections\SectionDefinition;
use Aisg\Sections\SectionRegistry;
use Aisg\Sections\TemplateLinter;
use Aisg\Sections\ViewModelBuilder;
use App\Domain\Ai\JsonSchemaValidator;

function sectionsPath(): string
{
    return base_path('../../sections');
}

function definition(array $fields, string $template = '<div class="tw-p-4">{{content.title}}</div>', array $style = [], array $data = []): SectionDefinition
{
    return new SectionDefinition('test', 1, [
        'key' => 'test', 'version' => 1, 'fields' => $fields, 'style' => $style, 'data' => $data,
    ], ['key' => 'test', 'placement' => 'body', 'page_types' => ['home']], $template, '/tmp/test');
}

test('every section in the library loads and passes the structural rules', function () {
    $registry = SectionRegistry::fromDirectory(sectionsPath());

    expect(array_keys($registry->all()))->toEqualCanonicalizing([
        'benefits', 'categories-grid', 'cta-banner', 'faq', 'featured-product', 'footer-columns',
        'header-classic', 'hero-centered', 'hero-image-bg', 'hero-split', 'lp-comparison', 'lp-features',
        'lp-guarantee', 'lp-product-hero', 'lp-reviews', 'lp-sticky-atc', 'newsletter', 'product-grid', 'testimonials',
    ]);
});

test('every section passes its meta-schemas', function () {
    $validator = new JsonSchemaValidator;
    $sectionMeta = json_decode(file_get_contents(sectionsPath().'/_meta-schema/section.schema.json'), true);
    $metaMeta = json_decode(file_get_contents(sectionsPath().'/_meta-schema/meta.schema.json'), true);

    foreach (glob(sectionsPath().'/[a-z]*', GLOB_ONLYDIR) as $dir) {
        expect($validator->errors(json_decode(file_get_contents("{$dir}/schema.json")), $sectionMeta))->toBe([], basename($dir))
            ->and($validator->errors(json_decode(file_get_contents("{$dir}/meta.json")), $metaMeta))->toBe([], basename($dir));
    }
});

test('sections that may contain example claims are flagged for review', function () {
    $flagged = array_keys(array_filter(
        SectionRegistry::fromDirectory(sectionsPath())->all(),
        fn (SectionDefinition $d) => $d->meta['review_required'] ?? false,
    ));

    expect($flagged)->toEqualCanonicalizing(['testimonials', 'lp-reviews', 'lp-comparison', 'lp-guarantee']);
});

test('the AI never writes customer names or review scores', function () {
    $registry = SectionRegistry::fromDirectory(sectionsPath());
    $compiler = new SchemaCompiler;

    foreach (['testimonials', 'lp-reviews'] as $key) {
        $item = json_decode(json_encode($compiler->aiSchema($registry->get($key))), true)['properties']['items']['items']['properties'];

        expect($item)->not->toHaveKey('name')->not->toHaveKey('rating');
    }
});

test('the registry filters by placement and page type', function () {
    $registry = SectionRegistry::fromDirectory(sectionsPath());

    expect(array_keys($registry->forPlacement('header')))->toBe(['header-classic'])
        ->and(array_keys($registry->forPlacement('footer')))->toBe(['footer-columns'])
        ->and(array_keys($registry->forPageType('faq')))->toBe(['faq'])
        ->and(array_keys($registry->forPageType('home')))->toContain('hero-split', 'product-grid', 'faq');
});

test('the ai schema contains only generated fields and simple keywords', function () {
    $registry = SectionRegistry::fromDirectory(sectionsPath());
    $schema = json_decode(json_encode((new SchemaCompiler)->aiSchema($registry->get('hero-split'))), true);

    expect(array_keys($schema['properties']))->toBe(['eyebrow', 'headline', 'subheadline', 'primary_cta', 'highlights'])
        ->and($schema['required'])->toBe(['eyebrow', 'headline', 'subheadline', 'primary_cta', 'highlights'])
        ->and($schema['properties']['primary_cta'])->toMatchArray(['type' => 'object', 'required' => ['label']])
        ->and($schema['properties']['highlights']['maxItems'])->toBe(3)
        ->and($schema['properties']['highlights']['items']['properties']['icon']['enum'])->toBe(Icons::NAMES)
        ->and($schema['properties']['headline']['description'])->toContain('max 80 characters')
        ->and(json_encode($schema))->not->toContain('maxLength');
});

test('defaults are complete and valid against the content schema', function () {
    $registry = SectionRegistry::fromDirectory(sectionsPath());
    $compiler = new SchemaCompiler;
    $validator = new JsonSchemaValidator;

    foreach ($registry->all() as $key => $definition) {
        $defaults = $compiler->defaults($definition);

        expect($validator->errors($defaults['content'], $compiler->contentSchema($definition)))->toBe([], "{$key} content")
            ->and($validator->errors($defaults['style'], $compiler->styleSchema($definition)))->toBe([], "{$key} style");
    }
});

test('ai output is merged over defaults and never touches non-generated fields', function () {
    $registry = SectionRegistry::fromDirectory(sectionsPath());
    $compiler = new SchemaCompiler;

    $content = $compiler->mergeAiOutput($registry->get('hero-split'), [
        'headline' => 'Mugs made by hand',
        'primary_cta' => ['label' => 'Browse', 'target' => ['kind' => 'url', 'value' => 'javascript:alert(1)']],
        'highlights' => [['icon' => 'truck', 'text' => 'Fast shipping']],
        'image' => ['url' => 'https://evil.test/x.png'],
    ]);

    expect($content['headline'])->toBe('Mugs made by hand')
        ->and($content['primary_cta'])->toBe(['label' => 'Browse', 'target' => ['kind' => 'system', 'value' => 'shop']])
        ->and($content['image'])->toBe(['asset_id' => null, 'url' => null, 'alt' => ''])
        ->and($content['highlights'])->toBe([['icon' => 'truck', 'text' => 'Fast shipping']])
        ->and($content['eyebrow'])->toBe('New collection');
});

test('the view model adds select flags, repeater metadata and resolved links', function () {
    $def = definition(
        fields: [
            ['name' => 'title', 'type' => 'text', 'label' => 'T'],
            ['name' => 'cta', 'type' => 'link', 'label' => 'C'],
            ['name' => 'items', 'type' => 'repeater', 'label' => 'I', 'fields' => [['name' => 'text', 'type' => 'text', 'label' => 'x']]],
        ],
        style: [['name' => 'align', 'type' => 'select', 'label' => 'A', 'default' => 'start', 'options' => [['value' => 'start'], ['value' => 'center']]]],
    );

    $context = new RenderContext(['name' => 'Shop', 'lang' => 'ar', 'dir' => 'rtl', 'year' => 2026], fn ($t) => $t['kind'] === 'system' ? '/shop' : null);

    $vm = (new ViewModelBuilder)->build($def, '01ABC', [
        'title' => 'Hi',
        'cta' => ['label' => 'Go', 'target' => ['kind' => 'system', 'value' => 'shop']],
        'items' => [['text' => 'a'], ['text' => 'b']],
    ], ['align' => 'center'], ['products' => []], $context);

    expect($vm['section'])->toBe(['id' => '01ABC', 'key' => 'test', 'anchor' => 's-01abc'])
        ->and($vm['site'])->toBe(['name' => 'Shop', 'lang' => 'ar', 'dir' => 'rtl', 'currency' => '', 'year' => 2026])
        ->and($vm['style'])->toBe(['align' => 'center', 'align_is_start' => false, 'align_is_center' => true])
        ->and($vm['content']['cta'])->toBe(['label' => 'Go', 'href' => '/shop', 'is_external' => false])
        ->and($vm['content']['items'][0])->toBe(['text' => 'a', '_index' => 1, '_first' => true, '_last' => false, '_even' => false])
        ->and($vm['content']['items'][1]['_even'])->toBeTrue()
        ->and($vm['content']['items'][1]['_last'])->toBeTrue();
});

test('unsafe urls are neutralised', function (string $url, string $expected) {
    expect(RenderContext::safeUrl($url))->toBe($expected);
})->with([
    ['https://example.com/a', 'https://example.com/a'],
    ['/shop', '/shop'],
    ['mailto:a@b.co', 'mailto:a@b.co'],
    ['#faq', '#faq'],
    ['javascript:alert(1)', '#'],
    ['JaVaScRiPt:alert(1)', '#'],
    ['data:text/html,x', '#'],
    ['//evil.test', '#'],
    ['', '#'],
]);

test('the renderer escapes every content value, including quotes', function () {
    $def = definition([['name' => 'title', 'type' => 'text', 'label' => 'T']], '<p title="{{content.title}}">{{content.title}}</p>');
    $vm = (new ViewModelBuilder)->build($def, 'x', ['title' => "<script>alert('x')</script> & \"q\""], [], [], new RenderContext);

    expect((new Renderer)->render($def, $vm))
        ->toBe('<p title="&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; &amp; &quot;q&quot;">&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; &amp; &quot;q&quot;</p>');
});

test('the wrapper carries direction and language', function () {
    expect(Renderer::wrap('<p>x</p>', ['dir' => 'rtl', 'lang' => 'ar']))->toBe('<div class="aisg" dir="rtl" lang="ar"><p>x</p></div>');
});

test('every library section renders its defaults', function () {
    $registry = SectionRegistry::fromDirectory(sectionsPath());
    $compiler = new SchemaCompiler;
    $data = [
        'menu' => ['items' => [['label' => 'Home', 'href' => '/']], 'home_href' => '/', 'cart_href' => '/cart'],
        'products' => [['id' => 1, 'name' => 'Mug', 'url' => '/p/1', 'image' => ['url' => '', 'alt' => ''], 'price_html' => '<span>$10</span>', 'add_to_cart_url' => '?add-to-cart=1']],
        'product' => ['id' => 1, 'name' => 'Mug', 'url' => '/p/1', 'image' => ['url' => '', 'alt' => ''], 'gallery' => [['url' => '', 'alt' => 'a']], 'attributes' => [], 'price_html' => '$10', 'add_to_cart_url' => '#'],
        'categories' => [['id' => 1, 'name' => 'Cups', 'url' => '/c/cups', 'image' => ['url' => '', 'alt' => '']]],
    ];

    foreach ($registry->all() as $key => $definition) {
        $defaults = $compiler->defaults($definition);
        $vm = (new ViewModelBuilder)->build($definition, 'sec1', $defaults['content'], $defaults['style'], $data, new RenderContext(['name' => 'Clay']));
        $html = (new Renderer)->render($definition, $vm);

        expect($html)->toContain("data-aisg-section=\"{$key}\"")->not->toContain('{{');
    }
});

test('the linter rejects markup risks and unprefixed or physical classes', function (string $template, string $error) {
    expect(implode("\n", TemplateLinter::lint($template)))->toContain($error);
})->with([
    'partial' => ['{{> header}}', 'partials'],
    'unescaped content' => ['{{{content.title}}}', 'unescaped output of [content.title]'],
    'ampersand tag' => ['{{& content.title}}', 'unescaped {{&'],
    'unprefixed class' => ['<div class="flex">', 'must use the tw- or aisg- prefix'],
    'physical margin' => ['<div class="tw-ml-4">', 'physical direction'],
    'interpolated class' => ['<div class="tw-bg-{{style.bg}}">', 'interpolates a variable'],
]);

test('the linter accepts allowlisted patterns', function () {
    expect(TemplateLinter::lint(
        '<div class="tw-p-4 md:tw-flex {{#style.a_is_b}}tw-bg-primary{{/style.a_is_b}} aisg-icon aisg-icon--{{icon}}" :class="open && \'tw-block\'">{{{price_html}}}</div>'
    ))->toBe([]);
});

test('invalid sections are rejected with readable errors', function () {
    $dir = sys_get_temp_dir().'/aisg-bad-'.uniqid();
    mkdir($dir.'/bad', 0777, true);
    file_put_contents("{$dir}/bad/schema.json", json_encode(['key' => 'bad', 'version' => 1, 'fields' => [
        ['name' => 'Bad Name', 'type' => 'text', 'label' => 'x'],
        ['name' => 'img', 'type' => 'image', 'label' => 'x', 'ai' => ['generate' => true]],
        ['name' => 'list', 'type' => 'repeater', 'label' => 'x', 'fields' => [['name' => 'inner', 'type' => 'repeater', 'label' => 'y', 'fields' => [['name' => 'z', 'type' => 'text', 'label' => 'z']]]]],
    ], 'style' => [], 'data' => []]));
    file_put_contents("{$dir}/bad/meta.json", json_encode(['key' => 'bad', 'placement' => 'body']));
    file_put_contents("{$dir}/bad/template.mustache", '<div class="flex"></div>');

    try {
        SectionRegistry::fromDirectory($dir);
        $this->fail('Expected InvalidSectionException');
    } catch (InvalidSectionException $e) {
        expect($e->getMessage())
            ->toContain('must be snake_case')
            ->toContain('[image] cannot be AI-generated')
            ->toContain('not allowed inside a repeater')
            ->toContain('must use the tw- or aisg- prefix');
    }
});

test('every selectable icon has CSS', function () {
    $built = json_decode(file_get_contents(base_path('../../packages/section-styles/dist/icons.json')), true);

    expect(array_diff(Icons::NAMES, $built))->toBe([]);
});

test('design tokens become Elementor-compatible css variables', function () {
    $css = DesignTokensCss::toCss([
        'colors' => ['primary' => '#7C4A1E', 'text' => '#111111', 'background' => 'red;} body{display:none'],
        'fonts' => ['heading' => ['family' => 'Playfair Display'], 'body' => ['family' => 'Inter"; }']],
        'radius' => 'lg',
        'spacing' => 'airy',
        'container_width' => 5000,
    ]);

    expect($css)
        ->toContain('--e-global-color-primary:#7c4a1e;')
        ->toContain('--e-global-color-text:#111111;')
        ->toContain('--e-global-typography-primary-font-family:"Playfair Display";')
        ->toContain('--aisg-token-radius:1rem;')
        ->toContain('--aisg-token-space-section:7rem;')
        ->toContain('--aisg-token-container:1920px;')
        ->not->toContain('display:none')
        ->not->toContain('Inter"');
});
