<?php

namespace Aisg\Sections;

/**
 * Builds the Mustache view model for one section. The rules are a contract
 * shared with the TypeScript builder (docs/ARCHITECTURE.md §4.4) so both
 * runtimes render byte-identical HTML:
 *
 *  - missing content/style values fall back to schema defaults
 *  - every select value v of field f adds "f_is_v": true (content and style)
 *  - repeater items get _index (1-based), _first, _last, _even (2nd, 4th... item)
 *  - a link becomes {label, href, is_external}
 *  - an image becomes {url, alt}
 *  - "section" = {id, key, anchor}; "site" = RenderContext::siteViewModel(); "data" = resolver output
 */
final class ViewModelBuilder
{
    public function __construct(private readonly SchemaCompiler $compiler = new SchemaCompiler) {}

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $data  Pre-resolved dynamic data keyed like schema.json "data".
     * @return array<string, mixed>
     */
    public function build(
        SectionDefinition $definition,
        string $sectionId,
        array $content,
        array $style,
        array $data,
        RenderContext $context,
    ): array {
        $defaults = $this->compiler->defaults($definition);

        return [
            'section' => [
                'id' => $sectionId,
                'key' => $definition->key,
                'anchor' => 's-'.strtolower($sectionId),
            ],
            'site' => $context->siteViewModel(),
            'content' => $this->fields($definition->fields(), $content + $defaults['content'], $context),
            'style' => $this->fields($definition->styleFields(), $style + $defaults['style'], $context),
            'data' => $data,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function fields(array $fields, array $values, RenderContext $context): array
    {
        $out = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $value = $values[$name] ?? null;

            switch ($field['type']) {
                case FieldTypes::SELECT:
                    $out[$name] = (string) $value;
                    foreach ($field['options'] as $option) {
                        $out[$name.'_is_'.$option['value']] = (string) $option['value'] === (string) $value;
                    }
                    break;

                case FieldTypes::LINK:
                    $target = is_array($value) ? ($value['target'] ?? []) : [];
                    $href = $context->resolveHref($target);
                    $out[$name] = [
                        'label' => is_array($value) ? (string) ($value['label'] ?? '') : '',
                        'href' => $href,
                        'is_external' => (bool) preg_match('~^https?://~i', $href) && ($target['kind'] ?? 'url') === 'url',
                    ];
                    break;

                case FieldTypes::IMAGE:
                    $out[$name] = [
                        'url' => is_array($value) && ! empty($value['url']) ? RenderContext::safeUrl($value['url']) : '',
                        'alt' => is_array($value) ? (string) ($value['alt'] ?? '') : '',
                    ];
                    break;

                case FieldTypes::REPEATER:
                    $items = is_array($value) ? array_values($value) : [];
                    $count = count($items);
                    $out[$name] = array_map(
                        fn (array $item, int $i) => $this->fields($field['fields'], $item, $context) + [
                            '_index' => $i + 1,
                            '_first' => $i === 0,
                            '_last' => $i === $count - 1,
                            '_even' => $i % 2 === 1,
                        ],
                        $items,
                        array_keys($items),
                    );
                    break;

                case FieldTypes::TEXT:
                case FieldTypes::TEXTAREA:
                case FieldTypes::ICON:
                    $out[$name] = (string) $value;
                    break;

                default:
                    $out[$name] = $value;
            }
        }

        return $out;
    }
}
