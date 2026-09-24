<?php

namespace Aisg\Connector\Import;

use Aisg\Connector\Elementor\DocumentWriter;
use Aisg\Connector\Elementor\KitTokens;
use Aisg\Connector\Plugin;
use Aisg\Connector\Sections\Library;
use Aisg\Connector\Settings;
use RuntimeException;

/**
 * Every import operation, shared by the REST API (live publish) and the ZIP
 * package import, so both paths behave identically.
 */
final class Importer
{
    public const PAGE_TEMPLATE = 'elementor_header_footer';

    private Catalog $catalog;

    public function __construct(private readonly Media $media)
    {
        $this->catalog = new Catalog($media);

        // Elementor and WooCommerce check capabilities: act as the connected administrator.
        if (! current_user_can('manage_options') && ($user = Settings::actingUser())) {
            wp_set_current_user($user);
        }
    }

    public function media(): Media
    {
        return $this->media;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    public function designTokens(array $tokens, array $project): array
    {
        Plugin::enableElementorDefaults();
        Settings::updateSite(['tokens' => $tokens, 'project' => $project]);
        $kit = KitTokens::apply($tokens);

        return ['action' => 'updated', 'kit' => $kit];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): array<string, mixed>  $import
     * @return list<array<string, mixed>>
     */
    public function batch(array $items, callable $import): array
    {
        $results = [];

        foreach ($items as $item) {
            try {
                $results[] = ['status' => 'ok'] + $import($item);
            } catch (\Throwable $e) {
                $results[] = ['ref' => (string) ($item['ref'] ?? ''), 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public function asset(array $asset): array
    {
        return $this->media->import($asset);
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    public function category(array $category): array
    {
        return $this->catalog->category($category);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public function product(array $product): array
    {
        return $this->catalog->product($product);
    }

    /**
     * Header/footer → an Elementor library template printed site-wide by HeaderFooter.
     *
     * @param  array<string, mixed>  $part  {ref, kind, sections}
     * @return array<string, mixed>
     */
    public function layoutPart(array $part): array
    {
        $kind = $part['kind'] === 'footer' ? 'footer' : 'header';
        $ref = (string) $part['ref'];
        $existing = Refs::find('template', $ref);

        $id = $existing ?: wp_insert_post([
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'post_title' => 'AISG '.ucfirst($kind),
        ], true);

        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }

        update_post_meta($id, '_aisg_ref', $ref);
        update_post_meta($id, '_elementor_template_type', 'section');
        wp_set_object_terms($id, 'section', 'elementor_library_type');

        DocumentWriter::save($id, DocumentWriter::elements($part['sections'] ?? [], $this->media));
        Settings::updateSite([$kind.'_id' => $id]);
        Refs::remember('template', $ref, $id);

        return ['id' => $id, 'action' => $existing ? 'updated' : 'created', 'hash' => DocumentWriter::hash($id)];
    }

    /**
     * A page as Elementor containers. If the page was edited in WordPress since
     * the last publish (hash mismatch), it is left alone unless $force.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    public function page(array $page, bool $force): array
    {
        $ref = (string) $page['ref'];
        $existing = Refs::find('page', $ref);

        if ($existing && ! $force) {
            $published = (string) get_post_meta($existing, '_aisg_published_hash', true);

            if ($published !== '' && $published !== DocumentWriter::hash($existing)) {
                throw new ConflictException('This page was edited in WordPress since the last publish. Publish with "overwrite" to replace it.');
            }
        }

        $postarr = [
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => sanitize_text_field((string) $page['title']),
            'post_name' => sanitize_title((string) $page['slug']),
            'page_template' => self::PAGE_TEMPLATE,
            'menu_order' => (int) ($page['position'] ?? 0),
        ];

        if ($existing) {
            $postarr['ID'] = $existing; // also restores a page that was trashed
        }

        $id = $existing ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);

        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }

        update_post_meta($id, '_aisg_ref', $ref);
        update_post_meta($id, '_aisg_slug', (string) $page['slug']);
        update_post_meta($id, '_wp_page_template', self::PAGE_TEMPLATE);
        Refs::remember('page', $ref, (int) $id);

        DocumentWriter::save((int) $id, DocumentWriter::elements($page['sections'] ?? [], $this->media));

        $hash = DocumentWriter::hash((int) $id);
        update_post_meta($id, '_aisg_published_hash', $hash);

        return ['id' => (int) $id, 'action' => $existing ? 'updated' : 'created', 'hash' => $hash, 'url' => get_permalink((int) $id)];
    }

    /**
     * Page removed on the platform → moved to the trash (never hard-deleted).
     *
     * @return array<string, mixed>
     */
    public function trashPage(string $ref): array
    {
        $id = Refs::find('page', $ref);

        if ($id) {
            wp_trash_post($id);
        }

        return ['id' => $id, 'action' => $id ? 'trashed' : 'missing'];
    }

    /**
     * @param  list<array<string, mixed>>  $items  {label, target: {kind: page|system, ref}}
     * @return array<string, mixed>
     */
    public function menu(string $location, array $items): array
    {
        $name = 'AISG Main Menu';
        $menu = wp_get_nav_menu_object($name);
        $menuId = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu($name);

        foreach (wp_get_nav_menu_items($menuId) ?: [] as $item) {
            wp_delete_post($item->ID, true);
        }

        foreach ($items as $position => $item) {
            $target = $item['target'] ?? [];
            $objectId = match ($target['kind'] ?? '') {
                'page' => Refs::find('page', (string) $target['ref']),
                'system' => function_exists('wc_get_page_id') && ($target['ref'] ?? '') === 'shop' ? (int) wc_get_page_id('shop') : null,
                default => null,
            };

            if (! $objectId || $objectId < 1) {
                continue;
            }

            wp_update_nav_menu_item($menuId, 0, [
                'menu-item-title' => sanitize_text_field((string) $item['label']),
                'menu-item-object' => 'page',
                'menu-item-object-id' => $objectId,
                'menu-item-type' => 'post_type',
                'menu-item-status' => 'publish',
                'menu-item-position' => $position + 1,
            ]);
        }

        // Hello Elementor: menu-1 = header, menu-2 = footer. Assign to every free location.
        $locations = get_theme_mod('nav_menu_locations', []);
        foreach (array_keys(get_registered_nav_menus()) as $slot) {
            if ($slot === 'menu-1' || empty($locations[$slot])) {
                $locations[$slot] = $menuId;
            }
        }
        set_theme_mod('nav_menu_locations', $locations);

        return ['id' => $menuId, 'action' => $menu ? 'updated' : 'created'];
    }

    /**
     * @return array<string, mixed>
     */
    public function homepage(string $pageRef): array
    {
        $id = Refs::find('page', $pageRef);

        if (! $id) {
            throw new RuntimeException('The homepage has not been published yet.');
        }

        update_option('show_on_front', 'page');
        update_option('page_on_front', $id);

        return ['id' => $id, 'action' => 'updated'];
    }

    /**
     * @return array<string, mixed>
     */
    public function clearCache(): array
    {
        if (did_action('elementor/loaded')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        flush_rewrite_rules(false);
        wp_cache_flush();

        return ['action' => 'cleared'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function health(): array
    {
        global $wp_version;

        $theme = wp_get_theme();
        $uploads = wp_upload_dir();
        $containers = get_option('elementor_experiment-container', 'default');

        return [
            'ok' => true,
            'site_url' => home_url(),
            'versions' => [
                'wordpress' => $wp_version,
                'php' => PHP_VERSION,
                'elementor' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
                'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null,
                'plugin' => AISG_CONNECTOR_VERSION,
                'theme' => $theme->get_template().' '.$theme->get('Version'),
            ],
            'elementor' => [
                'active' => did_action('elementor/loaded') > 0,
                'containers' => $containers !== 'inactive',
            ],
            'sections' => array_map(fn ($d) => $d->version, Library::registry()->all()),
            'uploads_writable' => wp_is_writable($uploads['basedir']),
            'permalinks' => (string) get_option('permalink_structure'),
            'memory_limit' => ini_get('memory_limit'),
            'acting_user' => Settings::actingUser() > 0,
        ];
    }

    /**
     * @return array<string, list<array{ref: string, id: int}>>
     */
    public static function inventory(): array
    {
        $list = function (string $postType): array {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID AS id, m.meta_value AS ref FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_aisg_ref' WHERE p.post_type = %s AND p.post_status <> 'trash'",
                $postType,
            ), ARRAY_A);

            return array_map(fn ($r) => ['ref' => (string) $r['ref'], 'id' => (int) $r['id']], $rows ?: []);
        };

        return ['pages' => $list('page'), 'products' => $list('product'), 'templates' => $list('elementor_library')];
    }
}
