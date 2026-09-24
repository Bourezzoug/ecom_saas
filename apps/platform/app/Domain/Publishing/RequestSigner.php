<?php

namespace App\Domain\Publishing;

/**
 * HMAC request signing for platform → plugin calls (docs/ARCHITECTURE.md §7.3).
 *
 *   string-to-sign = timestamp \n nonce \n METHOD \n route \n sha256(body)
 *   signature      = hex(hmac_sha256(signing_secret, string-to-sign))
 *
 * "route" is the REST route (e.g. /aisg/v1/pages/01J...), independent of the
 * site's permalink setup. The plugin rebuilds the same string (Rest\SignatureVerifier)
 * and rejects stale timestamps and reused nonces.
 */
final class RequestSigner
{
    /**
     * @return array<string, string>
     */
    public static function headers(string $connectionId, string $secret, string $method, string $route, string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));

        return [
            'X-AISG-Connection' => $connectionId,
            'X-AISG-Timestamp' => (string) $timestamp,
            'X-AISG-Nonce' => $nonce,
            'X-AISG-Signature' => self::sign($secret, $timestamp, $nonce, $method, $route, $body),
        ];
    }

    public static function sign(string $secret, int $timestamp, string $nonce, string $method, string $route, string $body): string
    {
        $payload = implode("\n", [$timestamp, $nonce, strtoupper($method), $route, hash('sha256', $body)]);

        return hash_hmac('sha256', $payload, $secret);
    }
}
