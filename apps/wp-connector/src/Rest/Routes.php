<?php

namespace Aisg\Connector\Rest;

use Aisg\Connector\Import\ConflictException;
use Aisg\Connector\Import\Importer;
use Aisg\Connector\Import\Media;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API for the platform (namespace aisg/v1), every route HMAC-signed
 * (docs/ARCHITECTURE.md §7.1). Writes are idempotent upserts keyed by refs.
 */
final class Routes
{
    public const NAMESPACE = 'aisg/v1';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $this->route('GET', '/health', fn () => Importer::health());
        $this->route('GET', '/inventory', fn () => Importer::inventory());

        $this->route('PUT', '/design-tokens', fn (WP_REST_Request $r) => $this->importer()->designTokens(
            (array) $r->get_param('tokens'),
            (array) $r->get_param('project'),
        ));

        $this->route('PUT', '/assets', function (WP_REST_Request $r) {
            $importer = $this->importer();

            return ['results' => $importer->batch((array) $r->get_param('assets'), fn ($a) => $importer->asset($a))];
        });

        $this->route('PUT', '/categories', function (WP_REST_Request $r) {
            $importer = $this->importer();

            return ['results' => $importer->batch((array) $r->get_param('categories'), fn ($c) => $importer->category($c))];
        });

        $this->route('PUT', '/products', function (WP_REST_Request $r) {
            $importer = $this->importer();

            return ['results' => $importer->batch((array) $r->get_param('products'), fn ($p) => $importer->product($p))];
        });

        $this->route('PUT', '/layout-parts/(?P<kind>header|footer)', fn (WP_REST_Request $r) => $this->importer()->layoutPart(
            ['kind' => $r['kind']] + (array) $r->get_param('part'),
        ));

        $this->route('PUT', '/pages/(?P<ref>[A-Za-z0-9]{26})', fn (WP_REST_Request $r) => $this->importer()->page(
            ['ref' => $r['ref']] + (array) $r->get_param('page'),
            (bool) $r->get_param('force'),
        ));

        $this->route('DELETE', '/pages/(?P<ref>[A-Za-z0-9]{26})', fn (WP_REST_Request $r) => $this->importer()->trashPage((string) $r['ref']));

        $this->route('PUT', '/menus/(?P<location>[a-z0-9_-]+)', fn (WP_REST_Request $r) => $this->importer()->menu(
            (string) $r['location'],
            (array) $r->get_param('items'),
        ));

        $this->route('PUT', '/homepage', fn (WP_REST_Request $r) => $this->importer()->homepage((string) $r->get_param('page_ref')));

        $this->route('POST', '/cache/clear', fn () => $this->importer()->clearCache());
    }

    private function route(string $method, string $path, callable $handler): void
    {
        register_rest_route(self::NAMESPACE, $path, [
            'methods' => $method,
            'permission_callback' => [SignatureVerifier::class, 'verify'],
            'callback' => function (WP_REST_Request $request) use ($handler) {
                try {
                    return new WP_REST_Response($handler($request), 200);
                } catch (ConflictException $e) {
                    return new WP_Error('aisg_conflict', $e->getMessage(), ['status' => 409]);
                } catch (Throwable $e) {
                    error_log('[aisg] '.$request->get_route().': '.$e->getMessage());

                    return new WP_Error('aisg_failed', $e->getMessage(), ['status' => 422]);
                }
            },
        ]);
    }

    private function importer(): Importer
    {
        return new Importer(new Media);
    }
}
