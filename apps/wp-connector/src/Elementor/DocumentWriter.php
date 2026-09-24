<?php

namespace Aisg\Connector\Elementor;

use Aisg\Connector\Import\Media;
use Aisg\Connector\Import\Refs;
use Aisg\Connector\Sections\ContentMapper;
use Aisg\Connector\Sections\Library;
use RuntimeException;

/**
 * Package sections → Elementor data: one full-width Flexbox container per
 * section, holding one AISG widget. Element ids are derived from our section
 * refs, so re-publishing keeps the same ids. Saved through Elementor's
 * Documents API (not raw post meta), so Elementor manages versions and CSS.
 */
final class DocumentWriter
{
    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    public static function elements(array $sections, Media $media): array
    {
        $elements = [];

        foreach ($sections as $section) {
            $key = (string) $section['key'];

            if (! Library::has($key)) {
                continue; // Section unknown to this plugin version: skipped, reported by health.
            }

            $ref = (string) $section['ref'];
            $settings = ContentMapper::toSettings(
                Library::get($key),
                (array) $section['content'],
                (array) $section['style'],
                fn (?string $assetRef, string $url) => $media->attachment($assetRef, $url),
                fn (string $type, string $value) => Refs::find($type, $value),
            );

            $elements[] = [
                'id' => self::elementId('c', $ref),
                'elType' => 'container',
                'isInner' => false,
                'settings' => [
                    'content_width' => 'full',
                    'flex_direction' => 'column',
                    'flex_gap' => ['unit' => 'px', 'size' => 0, 'column' => '0', 'row' => '0', 'isLinked' => true],
                    'padding' => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true],
                    '_aisg_ref' => $ref,
                ],
                'elements' => [[
                    'id' => self::elementId('w', $ref),
                    'elType' => 'widget',
                    'widgetType' => 'aisg-'.$key,
                    'settings' => $settings,
                    'elements' => [],
                ]],
            ];
        }

        return $elements;
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     */
    public static function save(int $postId, array $elements): void
    {
        if (! did_action('elementor/loaded')) {
            throw new RuntimeException('Elementor is not active.');
        }

        $document = \Elementor\Plugin::$instance->documents->get($postId, false);

        if (! $document) {
            throw new RuntimeException("Elementor cannot edit post {$postId}.");
        }

        update_post_meta($postId, '_elementor_edit_mode', 'builder');
        $document->save(['elements' => $elements]);
    }

    /**
     * Hash of what Elementor stored, used to detect edits made in WordPress.
     */
    public static function hash(int $postId): string
    {
        return md5((string) get_post_meta($postId, '_elementor_data', true));
    }

    private static function elementId(string $prefix, string $ref): string
    {
        return substr(md5($prefix.$ref), 0, 7);
    }
}
