<?php

namespace Aisg\Connector\Admin;

use Aisg\Connector\Import\Importer;
use Aisg\Connector\Settings;
use Throwable;

/**
 * WP Admin › AISG Connector: connect with a key from the platform, see the
 * connection, disconnect, or import an exported package (no connection needed).
 */
final class AdminPage
{
    private const SLUG = 'aisg-connector';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_aisg_connect', [$this, 'connect']);
        add_action('admin_post_aisg_disconnect', [$this, 'disconnect']);
        add_action('admin_post_aisg_import', [$this, 'import']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('AISG Connector', 'aisg-connector'),
            __('AISG Connector', 'aisg-connector'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            'dashicons-store',
            58,
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $connection = Settings::connection();
        $notice = get_transient('aisg_admin_notice_'.get_current_user_id());
        delete_transient('aisg_admin_notice_'.get_current_user_id());

        echo '<div class="wrap"><h1>'.esc_html__('AISG Connector', 'aisg-connector').'</h1>';

        if (is_array($notice)) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($notice['type']), wp_kses_post($notice['message']));
        }

        echo '<h2>'.esc_html__('Connection', 'aisg-connector').'</h2>';

        if (Settings::isConnected()) {
            echo '<p>'.sprintf(
                /* translators: 1: project name, 2: platform URL */
                esc_html__('Connected to project %1$s on %2$s.', 'aisg-connector'),
                '<strong>'.esc_html((string) ($connection['project'] ?? '')).'</strong>',
                '<code>'.esc_html((string) ($connection['platform_url'] ?? '')).'</code>',
            ).'</p>';
            echo '<p>'.esc_html__('Publish from the platform: pages, products, menus and design are sent here automatically.', 'aisg-connector').'</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('aisg_disconnect');
            echo '<input type="hidden" name="action" value="aisg_disconnect">';
            submit_button(__('Disconnect', 'aisg-connector'), 'secondary');
            echo '</form>';
        } else {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><table class="form-table">';
            wp_nonce_field('aisg_connect');
            echo '<input type="hidden" name="action" value="aisg_connect">';
            echo '<tr><th><label for="aisg-platform">'.esc_html__('Platform URL', 'aisg-connector').'</label></th><td><input id="aisg-platform" name="platform_url" type="url" class="regular-text" required value="'.esc_attr((string) ($connection['platform_url'] ?? '')).'" placeholder="https://app.example.com"></td></tr>';
            echo '<tr><th><label for="aisg-key">'.esc_html__('Connection key', 'aisg-connector').'</label></th><td><input id="aisg-key" name="key" type="password" class="regular-text" required autocomplete="off"><p class="description">'.esc_html__('Copy it from your project › Publish › Connect WordPress.', 'aisg-connector').'</p></td></tr>';
            echo '</table>';
            submit_button(__('Connect', 'aisg-connector'));
            echo '</form>';
        }

        echo '<hr><h2>'.esc_html__('Import package', 'aisg-connector').'</h2>';
        echo '<p>'.esc_html__('Upload aisg-package.zip from a platform export to create or update this store without connecting.', 'aisg-connector').'</p>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('aisg_import');
        echo '<input type="hidden" name="action" value="aisg_import">';
        echo '<p><input type="file" name="package" accept=".zip,application/zip" required></p>';
        echo '<p><label><input type="checkbox" name="force" value="1"> '.esc_html__('Overwrite pages I edited in WordPress', 'aisg-connector').'</label></p>';
        submit_button(__('Import', 'aisg-connector'), 'secondary');
        echo '</form></div>';
    }

    public function connect(): void
    {
        $this->guard('aisg_connect');

        $platform = esc_url_raw(rtrim((string) wp_unslash($_POST['platform_url'] ?? ''), '/'));
        $key = sanitize_text_field((string) wp_unslash($_POST['key'] ?? ''));
        $health = Importer::health();

        $response = wp_remote_post($platform.'/api/connector/v1/handshake', [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['site_url' => home_url(), 'versions' => $health['versions']]),
        ]);

        $body = is_wp_error($response) ? null : json_decode((string) wp_remote_retrieve_body($response), true);
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

        if ($status !== 200 || empty($body['signing_secret'])) {
            $reason = is_wp_error($response) ? $response->get_error_message() : ($body['message'] ?? "HTTP {$status}");
            $this->redirect('error', sprintf(__('Connection failed: %s', 'aisg-connector'), esc_html((string) $reason)));
        }

        Settings::saveConnection([
            'platform_url' => $platform,
            'connection_id' => (string) $body['connection_id'],
            'signing_secret' => (string) $body['signing_secret'],
            'project' => (string) ($body['project'] ?? ''),
            'acting_user' => get_current_user_id(),
            'connected_at' => gmdate('c'),
            'key' => $key,
        ]);

        $this->redirect('success', __('Connected. You can now publish from the platform.', 'aisg-connector'));
    }

    public function disconnect(): void
    {
        $this->guard('aisg_disconnect');
        $connection = Settings::connection();

        if (! empty($connection['platform_url']) && ! empty($connection['key'])) {
            wp_remote_post($connection['platform_url'].'/api/connector/v1/disconnect', [
                'timeout' => 10,
                'headers' => ['Authorization' => 'Bearer '.$connection['key'], 'Accept' => 'application/json'],
            ]);
        }

        Settings::clearConnection();
        $this->redirect('success', __('Disconnected.', 'aisg-connector'));
    }

    public function import(): void
    {
        $this->guard('aisg_import');

        $file = $_FILES['package'] ?? null;

        if (! is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $file['tmp_name'])) {
            $this->redirect('error', __('Upload failed.', 'aisg-connector'));
        }

        try {
            $report = (new PackageImport)->run((string) $file['tmp_name'], ! empty($_POST['force']));
        } catch (Throwable $e) {
            $this->redirect('error', esc_html($e->getMessage()));
        }

        $message = sprintf(__('Import finished: %1$d done, %2$d failed, %3$d skipped (edited in WordPress).', 'aisg-connector'), $report['ok'], $report['failed'], $report['conflicts']);
        if ($report['messages']) {
            $message .= '<br>'.implode('<br>', array_map('esc_html', array_slice($report['messages'], 0, 10)));
        }

        $this->redirect($report['failed'] ? 'warning' : 'success', $message);
    }

    private function guard(string $action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'aisg-connector'), 403);
        }

        check_admin_referer($action);
    }

    private function redirect(string $type, string $message): never
    {
        set_transient('aisg_admin_notice_'.get_current_user_id(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(admin_url('admin.php?page='.self::SLUG));
        exit;
    }
}
