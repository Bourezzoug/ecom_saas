<?php

namespace Aisg\Sections;

/**
 * One section type as defined in /sections/{key}: schema.json + meta.json + template.mustache.
 */
final class SectionDefinition
{
    /**
     * @param  array<string, mixed>  $schema  Decoded schema.json
     * @param  array<string, mixed>  $meta  Decoded meta.json
     */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly array $schema,
        public readonly array $meta,
        public readonly string $template,
        public readonly string $path,
    ) {}

    public function name(): string
    {
        return (string) ($this->meta['name'] ?? $this->key);
    }

    public function category(): string
    {
        return (string) ($this->meta['category'] ?? 'content');
    }

    /**
     * "header", "body" or "footer".
     */
    public function placement(): string
    {
        return (string) ($this->meta['placement'] ?? 'body');
    }

    /**
     * @return list<string>
     */
    public function pageTypes(): array
    {
        return array_values($this->meta['page_types'] ?? []);
    }

    public function fitsPageType(string $type): bool
    {
        return in_array($type, $this->pageTypes(), true);
    }

    public function requiresWooCommerce(): bool
    {
        return (bool) ($this->meta['requires_woocommerce'] ?? false);
    }

    public function plannerDescription(): string
    {
        return (string) ($this->meta['planner_description'] ?? $this->name());
    }

    /**
     * Content fields (schema.json "fields").
     *
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        return array_values($this->schema['fields'] ?? []);
    }

    /**
     * Style fields (schema.json "style").
     *
     * @return list<array<string, mixed>>
     */
    public function styleFields(): array
    {
        return array_values($this->schema['style'] ?? []);
    }

    /**
     * Dynamic data declarations (schema.json "data"), e.g. {"products": {"resolver": "products", "from_field": "query"}}.
     *
     * @return array<string, array{resolver: string, from_field?: string}>
     */
    public function data(): array
    {
        return $this->schema['data'] ?? [];
    }

    /**
     * sha256 of the template, used for cache busting and parity checks.
     */
    public function templateHash(): string
    {
        return hash('sha256', $this->template);
    }
}
