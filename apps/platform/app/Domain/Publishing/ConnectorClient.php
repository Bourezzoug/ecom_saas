<?php

namespace App\Domain\Publishing;

use App\Domain\Publishing\Exceptions\ConnectorException;
use App\Models\WpConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Signed JSON calls to a site's AISG Connector REST API (namespace aisg/v1).
 * Uses ?rest_route= so it works whatever the site's permalink settings are.
 */
class ConnectorClient
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     *
     * @throws ConnectorException
     */
    public function call(WpConnection $connection, string $method, string $route, ?array $body = null): array
    {
        if (! $connection->isConnected()) {
            throw new ConnectorException('The WordPress site is not connected.', 0);
        }

        $route = '/aisg/v1/'.ltrim($route, '/');
        $json = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $url = self::siteBase((string) $connection->site_url).'/?rest_route='.rawurlencode($route);

        $headers = RequestSigner::headers($connection->id, (string) $connection->signing_secret, $method, $route, $json);

        try {
            $response = $this->http
                ->withHeaders($headers + ['Accept' => 'application/json'])
                ->timeout(config('connector.timeout', 60))
                ->connectTimeout(10)
                ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->withBody($json, 'application/json')
                ->send($method, $url);
        } catch (ConnectionException $e) {
            throw new ConnectorException("Could not reach the WordPress site: {$e->getMessage()}", 0, previous: $e);
        }

        return $this->decode($response);
    }

    /**
     * The URL the platform uses to reach a site (dev rewrites applied).
     */
    public static function siteBase(string $siteUrl): string
    {
        $siteUrl = rtrim($siteUrl, '/');

        foreach (config('connector.site_url_rewrites', []) as $from => $to) {
            if (str_starts_with($siteUrl, $from)) {
                return $to.substr($siteUrl, strlen($from));
            }
        }

        return $siteUrl;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $data = $response->json();

        if ($response->successful() && is_array($data)) {
            return $data;
        }

        $message = is_array($data) ? (string) ($data['message'] ?? $data['code'] ?? 'Error') : 'Unexpected response';

        if ($response->status() === 404 && is_array($data) && ($data['code'] ?? '') === 'rest_no_route') {
            $message = 'The AISG Connector plugin is not active on this site.';
        }

        throw new ConnectorException($message, $response->status(), is_array($data) ? $data : []);
    }
}
