<?php

namespace Aisg\Connector\Import;

use RuntimeException;

/**
 * Sideloads package images into the Media Library, once per asset ref
 * (attachment meta "_aisg_asset_ref"). Only images are accepted
 * (WordPress checks the real file type in media_handle_sideload()).
 */
final class Media
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** @var array<string, array<string, mixed>> Package assets by ref. */
    private array $assets = [];

    /**
     * @param  list<array<string, mixed>>  $assets
     * @param  string|null  $localRoot  ZIP import: folder that "file" paths are relative to.
     */
    public function __construct(array $assets = [], private readonly ?string $localRoot = null)
    {
        foreach ($assets as $asset) {
            $this->assets[(string) $asset['ref']] = $asset;
        }
    }

    /**
     * @param  array<string, mixed>  $asset  {ref, url, alt, file?}
     * @return array{ref: string, id: int, action: string}
     */
    public function import(array $asset): array
    {
        $ref = (string) $asset['ref'];
        $this->assets[$ref] = $asset;

        if ($existing = Refs::find('attachment', $ref)) {
            return ['ref' => $ref, 'id' => $existing, 'action' => 'unchanged'];
        }

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $tmp = $this->fetch($asset);
        $name = sanitize_file_name(basename((string) parse_url((string) $asset['url'], PHP_URL_PATH)) ?: $ref.'.jpg');

        $type = wp_check_filetype_and_ext($tmp, $name);
        if (! in_array($type['type'] ?? '', self::ALLOWED, true)) {
            @unlink($tmp);
            throw new RuntimeException('Not an image: '.$name);
        }

        $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, (string) ($asset['alt'] ?? ''));

        if (is_wp_error($id)) {
            @unlink($tmp);
            throw new RuntimeException($id->get_error_message());
        }

        update_post_meta($id, '_aisg_asset_ref', $ref);
        update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) ($asset['alt'] ?? '')));
        Refs::remember('attachment', $ref, (int) $id);

        return ['ref' => $ref, 'id' => (int) $id, 'action' => 'created'];
    }

    /**
     * Attachment for an image referenced by content (sideloaded on demand).
     *
     * @return array{id: int, url: string}|null
     */
    public function attachment(?string $ref, string $url): ?array
    {
        $ref ??= 'url-'.substr(hash('sha256', $url), 0, 24);

        try {
            $id = Refs::find('attachment', $ref) ?? $this->import($this->assets[$ref] ?? ['ref' => $ref, 'url' => $url, 'alt' => ''])['id'];
        } catch (\Throwable $e) {
            error_log('[aisg] image import failed: '.$e->getMessage());

            return null;
        }

        return ['id' => $id, 'url' => (string) wp_get_attachment_url($id)];
    }

    public function idFor(?string $ref): ?int
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        return Refs::find('attachment', $ref) ?? (isset($this->assets[$ref]) ? $this->attachment($ref, (string) $this->assets[$ref]['url'])['id'] ?? null : null);
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function fetch(array $asset): string
    {
        if ($this->localRoot && ! empty($asset['file'])) {
            $source = realpath($this->localRoot.'/'.$asset['file']);

            if ($source === false || ! str_starts_with($source, realpath($this->localRoot) ?: '//')) {
                throw new RuntimeException('Invalid media path in package.');
            }

            $tmp = wp_tempnam(basename($source));
            copy($source, $tmp);

            return $tmp;
        }

        $url = (string) $asset['url'];

        if (! preg_match('~^https?://~i', $url)) {
            throw new RuntimeException('Invalid image URL.');
        }

        // WordPress refuses hosts on private networks (SSRF guard). The connected
        // platform is trusted, so allow exactly its host, only for this download.
        $platformHost = (string) parse_url((string) (\Aisg\Connector\Settings::connection()['platform_url'] ?? ''), PHP_URL_HOST);
        $allow = static fn ($external, $host) => $external || ($platformHost !== '' && strcasecmp((string) $host, $platformHost) === 0);
        add_filter('http_request_host_is_external', $allow, 10, 2);

        try {
            $tmp = download_url($url, 30);
        } finally {
            remove_filter('http_request_host_is_external', $allow, 10);
        }

        if (is_wp_error($tmp)) {
            throw new RuntimeException('Download failed: '.$tmp->get_error_message());
        }

        return $tmp;
    }
}
