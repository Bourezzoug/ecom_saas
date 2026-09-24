<?php
/**
 * WP-CLI smoke check: `wp eval-file wp-content/plugins/aisg-connector/tests/smoke.php`
 * Prints the plugin's view of the site as JSON (used by the platform E2E test too).
 */

use Aisg\Connector\Import\Importer;
use Aisg\Connector\Sections\Library;

$widgets = array_values(array_filter(
    array_keys(\Elementor\Plugin::$instance->widgets_manager->get_widget_types()),
    fn ($k) => str_starts_with($k, 'aisg-'),
));

echo wp_json_encode([
    'sections' => count(Library::registry()->all()),
    'widgets' => count($widgets),
    'health' => Importer::health(),
    'inventory' => Importer::inventory(),
    'front_page' => (int) get_option('page_on_front'),
    'site' => get_option('aisg_site'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
