<?php

namespace Aisg\Connector;

use Aisg\Connector\Admin\AdminPage;
use Aisg\Connector\Elementor\Widgets;
use Aisg\Connector\Layout\HeaderFooter;
use Aisg\Connector\Layout\TokenStyles;
use Aisg\Connector\Rest\Routes;

/**
 * Wires the plugin together. Everything that needs Elementor or WooCommerce
 * checks for them, so the site never breaks if one is deactivated.
 */
final class Plugin
{
    public static function boot(): void
    {
        (new Routes)->register();
        (new AdminPage)->register();
        (new TokenStyles)->register();
        (new HeaderFooter)->register();

        add_action('elementor/loaded', static fn () => (new Widgets)->register());

        if (did_action('elementor/loaded')) {
            (new Widgets)->register();
        }

        add_action('admin_notices', [self::class, 'requirementsNotice']);
    }

    public static function activate(): void
    {
        self::enableElementorDefaults();
    }

    /**
     * Flexbox containers (required) and Elementor's performance experiments
     * (spec §6: "enable Elementor performance defaults where possible").
     */
    public static function enableElementorDefaults(): void
    {
        foreach (['container', 'e_optimized_markup', 'e_font_icon_svg', 'additional_custom_breakpoints', 'e_local_google_fonts'] as $experiment) {
            update_option("elementor_experiment-{$experiment}", 'active');
        }

        update_option('elementor_optimized_image_loading', '1');
        update_option('elementor_element_cache_ttl', '24');
    }

    public static function requirementsNotice(): void
    {
        if (did_action('elementor/loaded') && class_exists('WooCommerce')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            .esc_html__('AISG Connector requires Elementor and WooCommerce to be active.', 'aisg-connector')
            .'</p></div>';
    }

    public static function path(string $relative = ''): string
    {
        return AISG_CONNECTOR_DIR.ltrim($relative, '/');
    }

    public static function url(string $relative = ''): string
    {
        return AISG_CONNECTOR_URL.ltrim($relative, '/');
    }
}
