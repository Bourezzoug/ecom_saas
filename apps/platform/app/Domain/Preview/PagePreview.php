<?php

namespace App\Domain\Preview;

use Aisg\Sections\DesignTokensCss;
use Aisg\Sections\RenderContext;
use Aisg\Sections\Renderer;
use App\Domain\Design\FontCatalog;
use App\Domain\Sections\SectionLibrary;
use App\Enums\PageKind;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Project;
use Closure;

/**
 * Server-rendered, read-only preview of one page (header + sections + footer)
 * using the shared PHP renderer, the compiled section CSS and the project's
 * design tokens. The interactive editor preview (Mustache in JS) comes in M3;
 * this also serves as the reference output for JS/PHP parity tests.
 */
class PagePreview
{
    public function __construct(private readonly SectionLibrary $sections) {}

    /**
     * @param  Closure(Page): string  $pageUrl  URL of another page's preview (for menus and page links).
     * @param  Closure(string): string  $assetUrl  URL of a section runtime asset (sections.css, aisg.js...).
     */
    public function render(Project $project, Page $page, Closure $pageUrl, Closure $assetUrl): string
    {
        $project->loadMissing(['designTokens', 'pages.sections']);
        $tokens = $project->designTokens?->toTokens() ?? [];
        $resolver = new PreviewDataResolver($project, $pageUrl);

        $pagesBySlug = $project->pages->keyBy('slug');
        $context = new RenderContext(
            site: [
                'name' => $project->name,
                'lang' => $project->language,
                'dir' => $project->direction->value,
                'currency' => (string) $project->currency,
                'year' => (int) now()->format('Y'),
            ],
            linkResolver: function (array $target) use ($pagesBySlug, $pageUrl) {
                if ($target['kind'] === 'page' && $pagesBySlug->has($target['value'])) {
                    return $pageUrl($pagesBySlug->get($target['value']));
                }

                if ($target['kind'] === 'system' && $target['value'] === 'home') {
                    $home = $pagesBySlug->first(fn (Page $p) => $p->is_homepage);

                    return $home ? $pageUrl($home) : null;
                }

                return null; // shop/cart/product targets exist only on the real store
            },
        );

        $parts = $project->pages->keyBy(fn (Page $p) => $p->kind->value);
        $html = '';

        foreach ([$parts->get(PageKind::Header->value), $page, $parts->get(PageKind::Footer->value)] as $part) {
            foreach ($part === null ? [] : $part->sections as $section) {
                $html .= $this->renderSection($section, $resolver, $context);
            }
        }

        $site = $context->siteViewModel();
        $fonts = array_values(array_filter([
            $tokens['fonts']['heading']['family'] ?? null,
            $tokens['fonts']['body']['family'] ?? null,
        ]));

        return view('preview.page', [
            'title' => $page->title.' · '.$project->name,
            'lang' => $site['lang'],
            'dir' => $site['dir'],
            'tokensCss' => DesignTokensCss::toCss($tokens),
            'fontsUrl' => $fonts !== [] ? FontCatalog::googleFontsUrl($fonts) : null,
            'body' => Renderer::wrap($html, $site),
            'cssUrl' => $assetUrl('sections.css'),
            'runtimeUrl' => $assetUrl('aisg.js'),
            'alpineUrl' => $assetUrl('alpine.min.js'),
        ])->render();
    }

    private function renderSection(PageSection $section, PreviewDataResolver $resolver, RenderContext $context): string
    {
        if (! $this->sections->registry()->has($section->section_key)) {
            return '';
        }

        $definition = $this->sections->get($section->section_key);

        return $this->sections->render(
            $section->section_key,
            $section->id,
            $section->content,
            $section->style,
            $resolver->resolve($definition, $section->content),
            $context,
        );
    }
}
