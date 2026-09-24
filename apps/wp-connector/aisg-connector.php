<?php
/**
 * Plugin Name:       AISG Connector
 * Description:       Publishes stores built with AI Store Generator: pages as Elementor containers with editable AISG widgets, WooCommerce products and categories, menus and design tokens.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  elementor, woocommerce
 * Author:            AISG
 * License:           GPL-2.0-or-later
 * Text Domain:       aisg-connector
 */

defined('ABSPATH') || exit;

const AISG_CONNECTOR_VERSION = '0.2.0';
const AISG_CONNECTOR_FILE = __FILE__;
define('AISG_CONNECTOR_DIR', plugin_dir_path(__FILE__));
define('AISG_CONNECTOR_URL', plugin_dir_url(__FILE__));

$aisg_autoload = __DIR__.'/vendor/autoload.php';

if (! is_file($aisg_autoload) || ! is_dir(__DIR__.'/lib/renderer-php/src') || ! is_dir(__DIR__.'/sections')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            .esc_html__('AISG Connector is incomplete (missing vendor/, lib/ or sections/). Reinstall it from the ZIP exported by the platform.', 'aisg-connector')
            .'</p></div>';
    });

    return;
}

require $aisg_autoload;

register_activation_hook(__FILE__, [Aisg\Connector\Plugin::class, 'activate']);

add_action('plugins_loaded', [Aisg\Connector\Plugin::class, 'boot']);
