<?php

$rewrites = [];
foreach (array_filter(explode(',', (string) env('CONNECTOR_SITE_URL_REWRITES', ''))) as $pair) {
    [$from, $to] = array_pad(explode('=', $pair, 2), 2, null);
    if ($from && $to) {
        $rewrites[rtrim(trim($from), '/')] = rtrim(trim($to), '/');
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Platform URL as seen by WordPress sites
    |--------------------------------------------------------------------------
    |
    | Used by the plugin to call the platform API and to download media during
    | publish. In Docker dev, WordPress reaches the platform as http://nginx.
    |
    */

    'platform_url' => rtrim((string) env('CONNECTOR_PLATFORM_URL', env('APP_URL', 'http://localhost')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Site URL rewrites (dev only)
    |--------------------------------------------------------------------------
    |
    | A site reports its public URL (e.g. http://localhost:8081); inside Docker
    | the platform must call it as http://wp. Format: "public=internal,...".
    |
    */

    'site_url_rewrites' => $rewrites,

    'timeout' => (int) env('CONNECTOR_TIMEOUT', 60),

    // Max request skew accepted by the plugin (seconds).
    'signature_ttl' => 300,

    'batch' => [
        'assets' => 5,
        'categories' => 50,
        'products' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tested WordPress stack (docs/COMPATIBILITY.md)
    |--------------------------------------------------------------------------
    |
    | The health check warns (does not block) when a site runs other versions.
    |
    */

    'tested' => [
        'plugin' => '0.2.0',
        'wordpress' => '7.1',
        'elementor' => '4.3',
        'woocommerce' => '11.1',
        'php_min' => '8.1',
    ],

];
