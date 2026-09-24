<?php

namespace Aisg\Connector\Import;

use RuntimeException;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Categories and products into WooCommerce through its CRUD classes (never
 * raw SQL), matched by "_aisg_ref" meta so re-publishing updates in place.
 */
final class Catalog
{
    public function __construct(private readonly Media $media) {}

    /**
     * @param  array<string, mixed>  $category  {ref, parent_ref, name, slug, description, image_ref}
     * @return array{ref: string, id: int, action: string}
     */
    public function category(array $category): array
    {
        $ref = (string) $category['ref'];
        $parent = ! empty($category['parent_ref']) ? (int) Refs::find('category', (string) $category['parent_ref']) : 0;
        $args = [
            'name' => sanitize_text_field((string) $category['name']),
            'slug' => sanitize_title((string) $category['slug']),
            'description' => sanitize_textarea_field((string) ($category['description'] ?? '')),
            'parent' => $parent,
        ];

        $existing = Refs::find('category', $ref);
        $result = $existing
            ? wp_update_term($existing, 'product_cat', $args)
            : wp_insert_term($args['name'], 'product_cat', $args);

        if (is_wp_error($result)) {
            // Same slug created outside AISG: adopt it instead of failing.
            if ($result->get_error_code() === 'term_exists') {
                $result = ['term_id' => (int) $result->get_error_data()];
            } else {
                throw new RuntimeException($result->get_error_message());
            }
        }

        $id = (int) $result['term_id'];
        update_term_meta($id, '_aisg_ref', $ref);
        Refs::remember('category', $ref, $id);

        if (! empty($category['image_ref']) && ($imageId = $this->media->idFor((string) $category['image_ref']))) {
            update_term_meta($id, 'thumbnail_id', $imageId);
        }

        return ['ref' => $ref, 'id' => $id, 'action' => $existing ? 'updated' : 'created'];
    }

    /**
     * @param  array<string, mixed>  $data  Package product.
     * @return array{ref: string, id: int, action: string, error?: string}
     */
    public function product(array $data): array
    {
        $ref = (string) $data['ref'];
        $type = $data['type'] === 'variable' ? 'variable' : 'simple';
        $existingId = Refs::find('product', $ref);

        if ($existingId && wc_get_product($existingId)?->get_type() !== $type) {
            wp_set_object_terms($existingId, $type, 'product_type');
        }

        $product = $existingId ? wc_get_product($existingId) : ($type === 'variable' ? new WC_Product_Variable : new WC_Product_Simple);

        if (! $product instanceof WC_Product) {
            throw new RuntimeException('Could not load product.');
        }

        $warning = null;
        $product->set_name(sanitize_text_field((string) $data['name']));
        $product->set_slug(sanitize_title((string) $data['slug']));
        $product->set_short_description(wp_kses_post(nl2br(esc_html((string) ($data['short_description'] ?? '')))));
        $product->set_description(wp_kses_post(nl2br(esc_html((string) ($data['description'] ?? '')))));
        $product->set_status(($data['status'] ?? 'publish') === 'draft' ? 'draft' : 'publish');
        $product->set_featured((bool) ($data['featured'] ?? false));
        $product->set_category_ids(array_values(array_filter(array_map(fn ($r) => Refs::find('category', (string) $r), $data['category_refs'] ?? []))));

        try {
            $product->set_sku((string) ($data['sku'] ?? ''));
        } catch (\WC_Data_Exception $e) {
            $warning = 'SKU not set: '.$e->getMessage();
        }

        if ($type === 'simple') {
            $product->set_regular_price((string) ($data['regular_price'] ?? ''));
            $product->set_sale_price((string) ($data['sale_price'] ?? ''));
            $product->set_stock_status((string) ($data['stock_status'] ?? 'instock'));
            $quantity = $data['stock_quantity'] ?? null;
            $product->set_manage_stock($quantity !== null);
            $product->set_stock_quantity($quantity !== null ? (int) $quantity : null);
        }

        $images = array_values(array_filter(array_map(fn ($r) => $this->media->idFor((string) $r), $data['image_refs'] ?? [])));
        $product->set_image_id($images[0] ?? '');
        $product->set_gallery_image_ids(array_slice($images, 1));

        $attributes = [];
        foreach ($data['attributes'] ?? [] as $i => $attr) {
            $attribute = new WC_Product_Attribute;
            $attribute->set_name(sanitize_text_field((string) $attr['name']));
            $attribute->set_options(array_map('sanitize_text_field', (array) $attr['options']));
            $attribute->set_position($i);
            $attribute->set_visible(true);
            $attribute->set_variation($type === 'variable' && ! empty($attr['variation']));
            $attributes[] = $attribute;
        }
        $product->set_attributes($attributes);

        $product->update_meta_data('_aisg_ref', $ref);
        $id = $product->save();
        Refs::remember('product', $ref, $id);

        if ($type === 'variable') {
            $this->variations($id, $data['variations'] ?? []);
            WC_Product_Variable::sync($id);
        }

        return array_filter(['ref' => $ref, 'id' => $id, 'action' => $existingId ? 'updated' : 'created', 'error' => $warning]);
    }

    /**
     * @param  list<array<string, mixed>>  $variations
     */
    private function variations(int $parentId, array $variations): void
    {
        $keep = [];

        foreach ($variations as $data) {
            $ref = (string) ($data['ref'] ?? '');
            $existing = $ref !== '' ? get_posts(['post_type' => 'product_variation', 'post_parent' => $parentId, 'meta_key' => '_aisg_ref', 'meta_value' => $ref, 'fields' => 'ids', 'numberposts' => 1, 'post_status' => 'any']) : [];
            $variation = $existing ? new WC_Product_Variation((int) $existing[0]) : new WC_Product_Variation;

            $variation->set_parent_id($parentId);
            $attributes = [];
            foreach ((array) ($data['attributes'] ?? []) as $name => $value) {
                $attributes[sanitize_title((string) $name)] = (string) $value;
            }
            $variation->set_attributes($attributes);
            $variation->set_regular_price((string) ($data['regular_price'] ?? ''));
            $variation->set_sale_price((string) ($data['sale_price'] ?? ''));
            $variation->set_stock_status((string) ($data['stock_status'] ?? 'instock'));

            try {
                $variation->set_sku((string) ($data['sku'] ?? ''));
            } catch (\WC_Data_Exception) {
                // Duplicate SKU: keep the variation without one.
            }

            if (! empty($data['image_ref']) && ($image = $this->media->idFor((string) $data['image_ref']))) {
                $variation->set_image_id($image);
            }

            $variation->update_meta_data('_aisg_ref', $ref);
            $keep[] = $variation->save();
        }

        // Remove AISG-created variations that are no longer in the package.
        foreach (get_posts(['post_type' => 'product_variation', 'post_parent' => $parentId, 'meta_key' => '_aisg_ref', 'fields' => 'ids', 'numberposts' => -1, 'post_status' => 'any']) as $id) {
            if (! in_array((int) $id, $keep, true)) {
                wp_delete_post((int) $id, true);
            }
        }
    }
}
