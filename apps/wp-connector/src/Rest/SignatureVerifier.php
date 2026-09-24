<?php

namespace Aisg\Connector\Rest;

use Aisg\Connector\Settings;
use WP_Error;
use WP_REST_Request;

/**
 * Verifies platform → plugin requests (docs/ARCHITECTURE.md §7.3):
 *
 *   X-AISG-Connection  must match the stored connection id
 *   X-AISG-Timestamp   within ±300 s
 *   X-AISG-Nonce       never seen before (kept 10 minutes)
 *   X-AISG-Signature   hex(hmac_sha256(secret, ts \n nonce \n METHOD \n route \n sha256(body)))
 *
 * Mirrors App\Domain\Publishing\RequestSigner on the platform.
 */
final class SignatureVerifier
{
    public const TTL = 300;

    public static function verify(WP_REST_Request $request): bool|WP_Error
    {
        $connection = Settings::connection();

        if (empty($connection['connection_id']) || empty($connection['signing_secret'])) {
            return new WP_Error('aisg_not_connected', 'This site is not connected to the platform.', ['status' => 401]);
        }

        $id = (string) $request->get_header('x_aisg_connection');
        $timestamp = (int) $request->get_header('x_aisg_timestamp');
        $nonce = (string) $request->get_header('x_aisg_nonce');
        $signature = (string) $request->get_header('x_aisg_signature');

        if ($id === '' || $nonce === '' || $signature === '' || ! hash_equals((string) $connection['connection_id'], $id)) {
            return new WP_Error('aisg_unauthorized', 'Missing or invalid connection headers.', ['status' => 401]);
        }

        if (abs(time() - $timestamp) > self::TTL) {
            return new WP_Error('aisg_stale', 'Request timestamp is too old (check the server clocks).', ['status' => 401]);
        }

        if (! preg_match('/^[a-f0-9]{16,64}$/', $nonce) || get_transient('aisg_nonce_'.$nonce)) {
            return new WP_Error('aisg_replay', 'Request was already used.', ['status' => 401]);
        }

        $expected = self::sign((string) $connection['signing_secret'], $timestamp, $nonce, $request->get_method(), $request->get_route(), (string) $request->get_body());

        if (! hash_equals($expected, $signature)) {
            return new WP_Error('aisg_bad_signature', 'Invalid request signature.', ['status' => 401]);
        }

        set_transient('aisg_nonce_'.$nonce, 1, 2 * self::TTL);

        return true;
    }

    public static function sign(string $secret, int $timestamp, string $nonce, string $method, string $route, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [$timestamp, $nonce, strtoupper($method), $route, hash('sha256', $body)]), $secret);
    }
}
