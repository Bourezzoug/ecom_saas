<?php
/**
 * WP-CLI helper doing what the admin "Connect" button does, for automated E2E:
 *   AISG_PLATFORM=http://nginx AISG_KEY=... wp eval-file .../tests/connect.php
 */

use Aisg\Connector\Import\Importer;
use Aisg\Connector\Settings;

$platform = rtrim((string) getenv('AISG_PLATFORM'), '/');
$key = (string) getenv('AISG_KEY');

$response = wp_remote_post($platform.'/api/connector/v1/handshake', [
    'timeout' => 20,
    'headers' => ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
    'body' => wp_json_encode(['site_url' => home_url(), 'versions' => Importer::health()['versions']]),
]);

$status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
$body = is_wp_error($response) ? ['message' => $response->get_error_message()] : json_decode((string) wp_remote_retrieve_body($response), true);

if ($status !== 200) {
    WP_CLI::error("Handshake failed ({$status}): ".wp_json_encode($body));
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);

Settings::saveConnection([
    'platform_url' => $platform,
    'connection_id' => $body['connection_id'],
    'signing_secret' => $body['signing_secret'],
    'project' => $body['project'],
    'acting_user' => (int) ($admins[0] ?? 1),
    'connected_at' => gmdate('c'),
    'key' => $key,
]);

WP_CLI::success('Connected to '.$body['project']);
