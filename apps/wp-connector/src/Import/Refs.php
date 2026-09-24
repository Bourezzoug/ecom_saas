<?php

namespace Aisg\Connector\Import;

/**
 * Our refs (platform ULIDs) ↔ WordPress ids, stored as "_aisg_ref" meta on
 * posts and terms. This meta is the source of truth for "update, don't duplicate".
 */
final class Refs
{
    /** @var array<string, int|null> */
    private static array $cache = [];

    /**
     * @param  string  $type  page|product|category|template|attachment
     */
    public static function find(string $type, string $ref): ?int
    {
        $key = "{$type}:{$ref}";

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        if ($type === 'category') {
            // Direct lookup: WooCommerce rewrites get_terms() queries on product_cat
            // (ordering by its own term meta), which breaks meta_key filtering.
            global $wpdb;
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT tm.term_id FROM {$wpdb->termmeta} tm JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = 'product_cat' WHERE tm.meta_key = '_aisg_ref' AND tm.meta_value = %s LIMIT 1",
                $ref,
            ));

            return self::$cache[$key] = $id ? (int) $id : null;
        }

        $postType = match ($type) {
            'page' => 'page',
            'product' => 'product',
            'template' => 'elementor_library',
            'attachment' => 'attachment',
            default => 'any',
        };

        $metaKey = $type === 'attachment' ? '_aisg_asset_ref' : '_aisg_ref';

        $ids = get_posts([
            'post_type' => $postType,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'inherit', 'future'],
            'meta_key' => $metaKey,
            'meta_value' => $ref,
            'numberposts' => 1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);

        return self::$cache[$key] = $ids ? (int) $ids[0] : null;
    }

    public static function remember(string $type, string $ref, int $id): void
    {
        self::$cache["{$type}:{$ref}"] = $id;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
