<?php

namespace Aisg\Connector\Elementor;

use Aisg\Connector\Sections\ContentMapper;
use Aisg\Sections\FieldTypes;
use Aisg\Sections\Icons;
use Aisg\Sections\SectionDefinition;
use Elementor\Controls_Manager;
use Elementor\Controls_Stack;
use Elementor\Repeater;

/**
 * schema.json → Elementor panel controls (spec §6: "widget controls generated
 * from schema.json"). Content fields go in the Content tab, style fields in the
 * Style tab. Control ids follow ContentMapper so saved settings round-trip.
 */
final class ControlsBuilder
{
    public static function build(Controls_Stack $widget, SectionDefinition $definition): void
    {
        $widget->start_controls_section('aisg_content', [
            'label' => $definition->name(),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        foreach ($definition->fields() as $field) {
            self::add($widget, $field, ContentMapper::PREFIX.$field['name']);
        }

        if ($definition->meta['review_required'] ?? false) {
            $widget->add_control('aisg_review_notice', [
                'type' => Controls_Manager::RAW_HTML,
                'raw' => esc_html__('This section contains example content (reviews, promises or comparisons). Make sure it is real and accurate.', 'aisg-connector'),
                'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
            ]);
        }

        $widget->end_controls_section();

        if ($definition->styleFields() !== []) {
            $widget->start_controls_section('aisg_style', [
                'label' => __('Section style', 'aisg-connector'),
                'tab' => Controls_Manager::TAB_STYLE,
            ]);

            foreach ($definition->styleFields() as $field) {
                self::add($widget, $field, ContentMapper::PREFIX.$field['name']);
            }

            $widget->end_controls_section();
        }
    }

    /**
     * @param  Controls_Stack|Repeater  $target
     * @param  array<string, mixed>  $field
     */
    private static function add($target, array $field, string $id): void
    {
        $label = (string) ($field['label'] ?? $field['name']);
        $default = $field['default'] ?? null;
        $max = $field['constraints']['maxLength'] ?? null;
        $hint = $max ? sprintf(__('Up to %d characters.', 'aisg-connector'), $max) : '';

        switch ($field['type']) {
            case FieldTypes::TEXT:
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::TEXT, 'default' => (string) $default, 'label_block' => true, 'description' => $hint]);
                break;

            case FieldTypes::TEXTAREA:
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::TEXTAREA, 'default' => (string) $default, 'rows' => 4, 'description' => $hint]);
                break;

            case FieldTypes::NUMBER:
                $target->add_control($id, [
                    'label' => $label,
                    'type' => Controls_Manager::NUMBER,
                    'default' => $default ?? ($field['constraints']['min'] ?? 0),
                    'min' => $field['constraints']['min'] ?? null,
                    'max' => $field['constraints']['max'] ?? null,
                ]);
                break;

            case FieldTypes::BOOLEAN:
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::SWITCHER, 'default' => $default ? 'yes' : '', 'return_value' => 'yes']);
                break;

            case FieldTypes::SELECT:
                $options = [];
                foreach ($field['options'] as $option) {
                    $options[(string) $option['value']] = (string) ($option['label'] ?? $option['value']);
                }
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::SELECT, 'options' => $options, 'default' => (string) ($default ?? array_key_first($options))]);
                break;

            case FieldTypes::ICON:
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::SELECT, 'options' => array_combine(Icons::NAMES, Icons::NAMES), 'default' => (string) ($default ?? 'check')]);
                break;

            case FieldTypes::COLOR:
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::COLOR, 'default' => (string) $default]);
                break;

            case FieldTypes::LINK:
                $target->add_control($id.'_label', ['label' => $label, 'type' => Controls_Manager::TEXT, 'default' => (string) ($default['label'] ?? ''), 'label_block' => true]);
                $target->add_control($id.'_url', [
                    'label' => sprintf(__('%s link', 'aisg-connector'), $label),
                    'type' => Controls_Manager::URL,
                    'default' => ['url' => ContentMapper::encodeTarget($default['target'] ?? [])],
                    'options' => false,
                    'description' => __('Links like aisg:page:about point to your published pages and update automatically.', 'aisg-connector'),
                ]);
                break;

            case FieldTypes::IMAGE:
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::MEDIA, 'default' => ['url' => '']]);
                break;

            case FieldTypes::PRODUCT:
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::SELECT2, 'options' => self::productOptions(), 'label_block' => true, 'description' => __('Empty: newest product.', 'aisg-connector')]);
                break;

            case FieldTypes::CATEGORY:
                $target->add_control($id, ['label' => $label, 'type' => Controls_Manager::SELECT2, 'options' => self::categoryOptions(), 'label_block' => true]);
                break;

            case FieldTypes::PRODUCT_QUERY:
                $target->add_control($id.'_source', [
                    'label' => $label,
                    'type' => Controls_Manager::SELECT,
                    'options' => [
                        'latest' => __('Newest', 'aisg-connector'),
                        'featured' => __('Featured', 'aisg-connector'),
                        'on_sale' => __('On sale', 'aisg-connector'),
                        'category' => __('From a category', 'aisg-connector'),
                    ],
                    'default' => (string) ($default['source'] ?? 'latest'),
                ]);
                $target->add_control($id.'_category', [
                    'label' => __('Category', 'aisg-connector'),
                    'type' => Controls_Manager::SELECT2,
                    'options' => self::categoryOptions(),
                    'condition' => [$id.'_source' => 'category'],
                ]);
                $target->add_control($id.'_limit', ['label' => __('Number of products', 'aisg-connector'), 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => 24, 'default' => (int) ($default['limit'] ?? 8)]);
                break;

            case FieldTypes::REPEATER:
                $repeater = new Repeater;
                foreach ($field['fields'] as $child) {
                    self::add($repeater, $child, $child['name']);
                }

                $defaults = [];
                foreach (is_array($default) ? $default : [] as $item) {
                    $row = [];
                    foreach ($field['fields'] as $child) {
                        $value = $item[$child['name']] ?? ($child['default'] ?? '');
                        $row += match ($child['type']) {
                            FieldTypes::LINK => [$child['name'].'_label' => (string) ($value['label'] ?? ''), $child['name'].'_url' => ['url' => ContentMapper::encodeTarget($value['target'] ?? [])]],
                            FieldTypes::BOOLEAN => [$child['name'] => $value ? 'yes' : ''],
                            FieldTypes::IMAGE => [$child['name'] => ['url' => '']],
                            default => [$child['name'] => is_scalar($value) ? (string) $value : ''],
                        };
                    }
                    $defaults[] = $row;
                }

                $titleField = self::titleField($field);
                $target->add_control($id, [
                    'label' => $label,
                    'type' => Controls_Manager::REPEATER,
                    'fields' => $repeater->get_controls(),
                    'default' => $defaults,
                    'title_field' => $titleField ? '{{{ '.$titleField.' }}}' : null,
                    'max_items' => $field['constraints']['maxItems'] ?? null,
                ]);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private static function titleField(array $field): ?string
    {
        if (preg_match('/\{\{\s*(\w+)\s*\}\}/', (string) ($field['item_label'] ?? ''), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function productOptions(): array
    {
        static $options = null;

        if ($options === null) {
            $options = [];
            foreach (get_posts(['post_type' => 'product', 'post_status' => ['publish', 'draft'], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']) as $post) {
                $options[(string) $post->ID] = $post->post_title;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function categoryOptions(): array
    {
        static $options = null;

        if ($options === null) {
            $options = [];
            $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200]);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $options[(string) $term->term_id] = $term->name;
            }
        }

        return $options;
    }
}
