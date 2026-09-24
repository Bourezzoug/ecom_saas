<?php

namespace Aisg\Connector\Layout;

use Aisg\Connector\Elementor\Widgets;
use Aisg\Connector\Settings;

/**
 * Site-wide header and footer WITHOUT Elementor Pro's Theme Builder (risk R1):
 * both are saved as Elementor library templates (editable with Elementor free)
 * and printed by this class. Hello Elementor's own header/footer is switched
 * off through its "hello_elementor_header_footer" filter. WooCommerce pages
 * (shop, cart, checkout) get them too.
 */
final class HeaderFooter
{
    public function register(): void
    {
        add_filter('hello_elementor_header_footer', [$this, 'disableThemeHeaderFooter']);
        add_action('wp_body_open', [$this, 'header'], 5);
        add_action('get_footer', [$this, 'footer'], 5);
        add_action('wp_enqueue_scripts', [$this, 'assets'], 20);
    }

    public function disableThemeHeaderFooter(bool $display): bool
    {
        return $this->templateId('header') || $this->templateId('footer') ? false : $display;
    }

    public function header(): void
    {
        $this->print('header');
    }

    public function footer(): void
    {
        $this->print('footer');
    }

    /**
     * The header/footer appear on every page, so the section runtime must too.
     */
    public function assets(): void
    {
        if (! $this->templateId('header') && ! $this->templateId('footer')) {
            return;
        }

        wp_enqueue_style(Widgets::STYLE_HANDLE);
        wp_enqueue_script(Widgets::ALPINE_HANDLE);

        if (did_action('elementor/loaded')) {
            foreach (['header', 'footer'] as $kind) {
                if ($id = $this->templateId($kind)) {
                    $css = \Elementor\Core\Files\CSS\Post::create($id);
                    $css->enqueue();
                }
            }
        }
    }

    private function print(string $kind): void
    {
        $id = $this->templateId($kind);

        if (! $id || ! did_action('elementor/loaded')) {
            return;
        }

        // Elementor's own editor preview of the template itself must not duplicate it.
        if ((int) get_the_ID() === $id) {
            return;
        }

        echo \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id, true); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    private function templateId(string $kind): int
    {
        $id = (int) (Settings::site()[$kind.'_id'] ?? 0);

        return $id && get_post_status($id) === 'publish' ? $id : 0;
    }
}
