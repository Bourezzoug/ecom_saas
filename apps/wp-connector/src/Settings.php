<?php

namespace Aisg\Connector;

/**
 * Plugin state in wp_options (autoload off for secrets).
 */
final class Settings
{
    private const CONNECTION = 'aisg_connection';

    private const SITE = 'aisg_site';

    /**
     * @return array{platform_url?: string, connection_id?: string, signing_secret?: string, project?: string, acting_user?: int, connected_at?: string}
     */
    public static function connection(): array
    {
        $value = get_option(self::CONNECTION, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveConnection(array $data): void
    {
        update_option(self::CONNECTION, $data, false);
    }

    public static function clearConnection(): void
    {
        delete_option(self::CONNECTION);
    }

    public static function isConnected(): bool
    {
        $c = self::connection();

        return ! empty($c['connection_id']) && ! empty($c['signing_secret']);
    }

    /**
     * Site-level values from the last publish: project name/language/direction and design tokens.
     *
     * @return array{project?: array<string, mixed>, tokens?: array<string, mixed>, header_id?: int, footer_id?: int}
     */
    public static function site(): array
    {
        $value = get_option(self::SITE, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public static function updateSite(array $patch): void
    {
        update_option(self::SITE, array_merge(self::site(), $patch), true);
    }

    /**
     * The administrator imports run as (Elementor and WooCommerce check capabilities).
     */
    public static function actingUser(): int
    {
        $id = (int) (self::connection()['acting_user'] ?? 0);

        if ($id > 0 && user_can($id, 'manage_options')) {
            return $id;
        }

        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID']);

        return (int) ($admins[0] ?? 0);
    }
}
