<?php

namespace Aisg\Sections;

/**
 * Compiles a section's schema.json DSL into:
 *  - aiSchema():      the JSON Schema a model must fill (only ai.generate fields, simple keywords)
 *  - contentSchema(): the full JSON Schema content is validated against (AI output, editor saves)
 *  - styleSchema():   JSON Schema for style values
 *  - defaults():      default content and style values
 *  - mergeAiOutput(): AI output laid over the defaults
 */
final class SchemaCompiler
{
    /**
     * @return array<string, mixed>
     */
    public function aiSchema(SectionDefinition $definition): array
    {
        $properties = [];

        foreach ($definition->fields() as $field) {
            if ($this->isGenerated($field)) {
                $properties[$field['name']] = $this->aiField($field);
            }
        }

        return [
            'type' => 'object',
            'properties' => self::objectOrEmpty($properties),
            'required' => array_keys($properties),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contentSchema(SectionDefinition $definition): array
    {
        return $this->objectSchema($definition->fields());
    }

    /**
     * @return array<string, mixed>
     */
    public function styleSchema(SectionDefinition $definition): array
    {
        return $this->objectSchema($definition->styleFields());
    }

    /**
     * @return array{content: array<string, mixed>, style: array<string, mixed>}
     */
    public function defaults(SectionDefinition $definition): array
    {
        $content = [];
        foreach ($definition->fields() as $field) {
            $content[$field['name']] = $this->defaultFor($field);
        }

        $style = [];
        foreach ($definition->styleFields() as $field) {
            $style[$field['name']] = $this->defaultFor($field);
        }

        return ['content' => $content, 'style' => $style];
    }

    /**
     * Lay validated AI output over the section defaults. Non-generated fields
     * (images, URLs, link targets, product queries) always keep their defaults.
     *
     * @param  array<string, mixed>  $generated
     * @return array<string, mixed>
     */
    public function mergeAiOutput(SectionDefinition $definition, array $generated): array
    {
        $content = $this->defaults($definition)['content'];

        foreach ($definition->fields() as $field) {
            $name = $field['name'];

            if (! $this->isGenerated($field) || ! array_key_exists($name, $generated)) {
                continue;
            }

            $content[$name] = $this->mergeField($field, $content[$name], $generated[$name], trusted: false);
        }

        return $content;
    }

    /**
     * Whether the AI fills this field.
     *
     * @param  array<string, mixed>  $field
     */
    public function isGenerated(array $field): bool
    {
        return (bool) ($field['ai']['generate'] ?? false)
            && in_array($field['type'], FieldTypes::AI_GENERATABLE, true);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function aiField(array $field): array
    {
        $description = $this->describe($field);

        return match ($field['type']) {
            FieldTypes::TEXT, FieldTypes::TEXTAREA => ['type' => 'string', 'description' => $description],
            FieldTypes::NUMBER => ['type' => 'number', 'description' => $description],
            FieldTypes::BOOLEAN => ['type' => 'boolean', 'description' => $description],
            FieldTypes::SELECT => ['type' => 'string', 'enum' => $this->optionValues($field), 'description' => $description],
            FieldTypes::ICON => ['type' => 'string', 'enum' => Icons::NAMES, 'description' => $description],
            FieldTypes::LINK => [
                'type' => 'object',
                'properties' => ['label' => ['type' => 'string', 'description' => $description]],
                'required' => ['label'],
            ],
            FieldTypes::REPEATER => $this->aiRepeater($field, $description),
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function aiRepeater(array $field, string $description): array
    {
        $properties = [];
        foreach ($field['fields'] as $child) {
            if ($this->isGenerated($child)) {
                $properties[$child['name']] = $this->aiField($child);
            }
        }

        return array_filter([
            'type' => 'array',
            'description' => $description,
            'minItems' => $field['constraints']['minItems'] ?? null,
            'maxItems' => $field['constraints']['maxItems'] ?? null,
            'items' => [
                'type' => 'object',
                'properties' => self::objectOrEmpty($properties),
                'required' => array_keys($properties),
            ],
        ], fn ($value) => $value !== null);
    }

    /**
     * Human hint for the model: label, author hint and length limits.
     *
     * @param  array<string, mixed>  $field
     */
    private function describe(array $field): string
    {
        $parts = [$field['label'] ?? $field['name']];

        if (! empty($field['ai']['hint'])) {
            $parts[] = $field['ai']['hint'];
        }

        $constraints = $field['constraints'] ?? [];

        if (isset($constraints['maxLength'])) {
            $parts[] = "max {$constraints['maxLength']} characters";
        }

        if (isset($constraints['minItems'], $constraints['maxItems'])) {
            $parts[] = "{$constraints['minItems']} to {$constraints['maxItems']} items";
        }

        return implode('. ', $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function objectSchema(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field['name']] = $this->contentField($field);
        }

        return [
            'type' => 'object',
            'properties' => self::objectOrEmpty($properties),
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function contentField(array $field): array
    {
        $c = $field['constraints'] ?? [];
        $required = (bool) ($field['required'] ?? false);

        return match ($field['type']) {
            FieldTypes::TEXT, FieldTypes::TEXTAREA => array_filter([
                'type' => 'string',
                'minLength' => $c['minLength'] ?? ($required ? 1 : null),
                'maxLength' => $c['maxLength'] ?? null,
            ], fn ($v) => $v !== null),
            FieldTypes::NUMBER => array_filter([
                'type' => 'number',
                'minimum' => $c['min'] ?? null,
                'maximum' => $c['max'] ?? null,
            ], fn ($v) => $v !== null),
            FieldTypes::BOOLEAN => ['type' => 'boolean'],
            FieldTypes::SELECT => ['enum' => $this->optionValues($field)],
            FieldTypes::ICON => ['enum' => Icons::NAMES],
            FieldTypes::COLOR => [
                'type' => ['string', 'null'],
                'pattern' => '^(#[0-9a-fA-F]{6}|primary|secondary|accent|background|surface|text|muted)$',
            ],
            FieldTypes::LINK => [
                'type' => 'object',
                'required' => ['label', 'target'],
                'additionalProperties' => false,
                'properties' => [
                    'label' => array_filter([
                        'type' => 'string',
                        'minLength' => $required ? 1 : null,
                        'maxLength' => $c['maxLength'] ?? 60,
                    ], fn ($v) => $v !== null),
                    'target' => [
                        'type' => 'object',
                        'required' => ['kind', 'value'],
                        'additionalProperties' => false,
                        'properties' => [
                            'kind' => ['enum' => FieldTypes::LINK_TARGETS],
                            'value' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                        ],
                    ],
                ],
            ],
            FieldTypes::IMAGE => [
                'type' => 'object',
                'required' => ['asset_id', 'url', 'alt'],
                'additionalProperties' => false,
                'properties' => [
                    'asset_id' => ['type' => ['string', 'null']],
                    'url' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                    'alt' => ['type' => 'string', 'maxLength' => 200],
                ],
            ],
            FieldTypes::PRODUCT, FieldTypes::CATEGORY => ['type' => ['string', 'null']],
            FieldTypes::PRODUCT_QUERY => [
                'type' => 'object',
                'required' => ['source', 'limit'],
                'additionalProperties' => false,
                'properties' => [
                    'source' => ['enum' => ['latest', 'featured', 'on_sale', 'category', 'ids']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24],
                    'category' => ['type' => ['string', 'null']],
                    'ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            FieldTypes::REPEATER => array_filter([
                'type' => 'array',
                'minItems' => $c['minItems'] ?? null,
                'maxItems' => $c['maxItems'] ?? null,
                'items' => $this->objectSchema($field['fields']),
            ], fn ($v) => $v !== null),
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function defaultFor(array $field): mixed
    {
        $default = $field['default'] ?? null;

        return match ($field['type']) {
            FieldTypes::TEXT, FieldTypes::TEXTAREA => (string) ($default ?? ''),
            FieldTypes::NUMBER => $default ?? ($field['constraints']['min'] ?? 0),
            FieldTypes::BOOLEAN => (bool) ($default ?? false),
            FieldTypes::SELECT => $default ?? $this->optionValues($field)[0],
            FieldTypes::ICON => $default ?? Icons::NAMES[0],
            FieldTypes::COLOR, FieldTypes::PRODUCT, FieldTypes::CATEGORY => $default,
            FieldTypes::LINK => [
                'label' => (string) ($default['label'] ?? ''),
                'target' => [
                    'kind' => $default['target']['kind'] ?? 'url',
                    'value' => $default['target']['value'] ?? null,
                ],
            ],
            FieldTypes::IMAGE => [
                'asset_id' => $default['asset_id'] ?? null,
                'url' => $default['url'] ?? null,
                'alt' => (string) ($default['alt'] ?? ''),
            ],
            FieldTypes::PRODUCT_QUERY => [
                'source' => $default['source'] ?? 'latest',
                'limit' => (int) ($default['limit'] ?? 8),
                'category' => $default['category'] ?? null,
                'ids' => $default['ids'] ?? [],
            ],
            FieldTypes::REPEATER => array_map(
                fn (array $item) => $this->repeaterItem($field, $item, trusted: true),
                is_array($default) ? $default : [],
            ),
        };
    }

    /**
     * A repeater item with every sub-field present (missing ones defaulted).
     * Untrusted (AI) items only fill generated sub-fields.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function repeaterItem(array $field, array $values, bool $trusted): array
    {
        $item = [];
        foreach ($field['fields'] as $child) {
            $use = array_key_exists($child['name'], $values) && ($trusted || $this->isGenerated($child));

            $item[$child['name']] = $use
                ? $this->mergeField($child, $this->defaultFor($child), $values[$child['name']], $trusted)
                : $this->defaultFor($child);
        }

        return $item;
    }

    /**
     * @param  bool  $trusted  False for AI output: it may only set labels/text, never link targets.
     */
    private function mergeField(array $field, mixed $default, mixed $value, bool $trusted): mixed
    {
        return match ($field['type']) {
            FieldTypes::LINK => [
                'label' => is_array($value) ? (string) ($value['label'] ?? $default['label']) : (string) $value,
                'target' => $trusted && is_array($value) && isset($value['target']) ? $value['target'] : $default['target'],
            ],
            FieldTypes::REPEATER => array_map(
                fn ($item) => $this->repeaterItem($field, is_array($item) ? $item : [], $trusted),
                is_array($value) ? array_values($value) : [],
            ),
            default => $value,
        };
    }

    /**
     * Plain arrays for consumers; an empty map becomes stdClass so it JSON-encodes as {} not [].
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|\stdClass
     */
    private static function objectOrEmpty(array $properties): array|\stdClass
    {
        return $properties === [] ? new \stdClass : $properties;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function optionValues(array $field): array
    {
        return array_values(array_map(fn ($o) => (string) $o['value'], $field['options'] ?? []));
    }
}
