<?php

namespace Aisg\Connector\Elementor;

use Aisg\Connector\Sections\Library;
use Aisg\Connector\Sections\SectionRenderer;
use Elementor\Widget_Base;

/**
 * ONE widget class for every AISG section. Elementor instantiates widgets as
 * `new $class($data, $widgetType->get_default_args())`, so each registered
 * instance carries its section key in $args (the same pattern Elementor uses
 * for its WordPress-widget bridge). get_name() → "aisg-{key}".
 *
 * Rendering always happens server-side with the shared Mustache template, so
 * the Elementor editor preview is exactly what visitors see.
 */
class SectionWidget extends Widget_Base
{
    private string $sectionKey;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $args
     */
    public function __construct($data = [], $args = null)
    {
        $this->sectionKey = (string) ($args['section_key'] ?? '');

        parent::__construct($data, $args);
    }

    public function get_name(): string
    {
        return 'aisg-'.$this->sectionKey;
    }

    public function get_title(): string
    {
        return Library::has($this->sectionKey) ? Library::get($this->sectionKey)->name() : $this->sectionKey;
    }

    public function get_icon(): string
    {
        return match (Library::has($this->sectionKey) ? Library::get($this->sectionKey)->category() : '') {
            'header' => 'eicon-header',
            'footer' => 'eicon-footer',
            'hero' => 'eicon-banner',
            'commerce' => 'eicon-products',
            'social_proof' => 'eicon-testimonial',
            'cta' => 'eicon-call-to-action',
            'landing' => 'eicon-single-product',
            default => 'eicon-section',
        };
    }

    /**
     * @return list<string>
     */
    public function get_categories(): array
    {
        return [Widgets::CATEGORY];
    }

    /**
     * @return list<string>
     */
    public function get_keywords(): array
    {
        return ['aisg', 'ai', 'store', $this->sectionKey];
    }

    /**
     * Assets load only on pages that use AISG widgets (Elementor dependency API).
     *
     * @return list<string>
     */
    public function get_style_depends(): array
    {
        return [Widgets::STYLE_HANDLE];
    }

    /**
     * @return list<string>
     */
    public function get_script_depends(): array
    {
        return [Widgets::RUNTIME_HANDLE, Widgets::ALPINE_HANDLE];
    }

    public function has_widget_inner_wrapper(): bool
    {
        return false;
    }

    protected function is_dynamic_content(): bool
    {
        // Product grids, menus and prices change without the page being edited.
        return true;
    }

    protected function register_controls(): void
    {
        if (Library::has($this->sectionKey)) {
            ControlsBuilder::build($this, Library::get($this->sectionKey));
        }
    }

    protected function render(): void
    {
        // Output is escaped by the Mustache renderer (content) or produced by
        // WooCommerce (price_html, allowlisted by the template linter).
        echo SectionRenderer::render($this->sectionKey, (string) $this->get_id(), $this->get_settings_for_display()); // phpcs:ignore WordPress.Security.EscapeOutput
    }
}
