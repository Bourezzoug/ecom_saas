<?php

namespace App\Domain\Sections;

use Aisg\Sections\RenderContext;
use Aisg\Sections\Renderer;
use Aisg\Sections\SchemaCompiler;
use Aisg\Sections\SectionDefinition;
use Aisg\Sections\SectionRegistry;
use Aisg\Sections\ViewModelBuilder;

/**
 * Platform entry point to the shared section kit (packages/renderer-php).
 * Registered as a singleton; the registry is read from disk once per process.
 */
class SectionLibrary
{
    private ?SectionRegistry $registry = null;

    public function __construct(
        private readonly string $path,
        private readonly SchemaCompiler $compiler = new SchemaCompiler,
        private readonly Renderer $renderer = new Renderer,
    ) {}

    public function registry(): SectionRegistry
    {
        return $this->registry ??= SectionRegistry::fromDirectory($this->path);
    }

    public function get(string $key): SectionDefinition
    {
        return $this->registry()->get($key);
    }

    public function compiler(): SchemaCompiler
    {
        return $this->compiler;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Render one section instance to HTML (without the .aisg wrapper).
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $data
     */
    public function render(string $key, string $sectionId, array $content, array $style, array $data, RenderContext $context): string
    {
        $definition = $this->get($key);
        $viewModel = (new ViewModelBuilder($this->compiler))->build($definition, $sectionId, $content, $style, $data, $context);

        return $this->renderer->render($definition, $viewModel);
    }
}
