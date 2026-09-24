<?php

use Aisg\Sections\Icons;
use Aisg\Sections\RenderContext;
use Aisg\Sections\SectionDefinition;
use App\Domain\Ai\Drivers\FakeAiProvider;
use App\Domain\Preview\SampleCatalog;
use App\Domain\Sections\SectionLibrary;
use App\Domain\Sections\SectionPayload;
use App\Models\Project;
use Symfony\Component\Process\Process;

/**
 * The editor previews sections with @aisg/renderer (JS); WordPress renders them
 * with packages/renderer-php. This test proves both produce byte-identical HTML
 * for every section in the library, for defaults and for hostile content.
 */
function renderWithNode(array $jobs): array
{
    $process = new Process(
        ['node', '--experimental-strip-types', '--no-warnings', 'scripts/render.ts'],
        base_path('../../packages/renderer-js'),
    );
    $process->setInput(json_encode($jobs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $process->setTimeout(60);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Content full of characters that escaping must handle, laid over the AI-fillable fields.
 */
function hostileContent(SectionDefinition $definition, SectionLibrary $library): array
{
    $compiler = $library->compiler();
    $generated = (new FakeAiProvider)->exampleFor(json_decode(json_encode($compiler->aiSchema($definition)), true));

    array_walk_recursive($generated, function (&$value) {
        if (is_string($value) && ! in_array($value, Icons::NAMES, true) && ! preg_match('/^\d+$/', $value)) {
            $value = "<b>\"Q\" & 'A'</b> ".$value.' — عربي 🙂';
        }
    });

    $content = $compiler->mergeAiOutput($definition, $generated);

    // Exercise image and link branches too.
    foreach ($definition->fields() as $field) {
        if ($field['type'] === 'image') {
            $content[$field['name']] = ['asset_id' => null, 'url' => 'https://cdn.example.com/a.jpg?x=1&y=2', 'alt' => 'Alt "quoted"'];
        }
        if ($field['type'] === 'link') {
            $content[$field['name']]['target'] = ['kind' => 'url', 'value' => 'javascript:alert(1)'];
        }
    }

    return $content;
}

test('JS and PHP renderers produce identical HTML for every section', function () {
    $library = app(SectionLibrary::class);
    $project = Project::factory()->create(['currency' => 'EUR', 'language' => 'fr']);
    $catalog = new SampleCatalog($project);

    $menu = ['items' => [['label' => 'Accueil', 'href' => '/'], ['label' => 'À propos & "nous"', 'href' => '/a-propos']], 'home_href' => '/', 'cart_href' => '/panier'];
    $site = ['name' => 'Clay & Co "Paris"', 'lang' => 'fr', 'dir' => 'ltr', 'currency' => 'EUR', 'year' => 2026];
    $links = ['system:shop' => '/boutique', 'system:home' => '/'];

    $jobs = [];
    $expected = [];

    foreach ($library->registry()->all() as $key => $definition) {
        $defaults = $library->compiler()->defaults($definition);
        $data = [
            'menu' => $menu,
            'products' => $catalog->products(4),
            'product' => $catalog->products(1)[0],
            'categories' => $catalog->categories(4),
        ];
        $data = array_intersect_key($data, $definition->data());

        foreach (['defaults' => $defaults['content'], 'hostile' => hostileContent($definition, $library)] as $case => $content) {
            $id = strtoupper(substr(md5($key.$case), 0, 26));
            $context = new RenderContext($site, fn (array $t) => $links["{$t['kind']}:{$t['value']}"] ?? null);

            $expected["{$key}/{$case}"] = $library->render($key, $id, $content, $defaults['style'], $data, $context);
            $jobs[] = [
                'definition' => SectionPayload::for($definition),
                'id' => $id,
                'content' => $content,
                'style' => $defaults['style'],
                'data' => $data === [] ? new stdClass : $data,
                'site' => $site,
                'links' => $links,
            ];
        }
    }

    $actual = array_combine(array_keys($expected), renderWithNode($jobs));

    foreach ($expected as $name => $html) {
        expect($actual[$name])->toBe($html, "Renderer mismatch for {$name}");
    }

    expect($expected)->toHaveCount(2 * count($library->registry()->all()))
        ->and(implode('', $expected))->not->toContain('javascript:')
        ->and(implode('', $expected))->not->toContain('<b>"Q"');
});
