<?php

namespace Aisg\Connector\Layout;

use Aisg\Connector\Settings;
use Aisg\Sections\DesignTokensCss;

/**
 * Prints the published design tokens as :root variables on every page.
 *
 * Colours/fonts are written under Elementor's --e-global-* names as a fallback
 * for pages where the Elementor kit CSS isn't loaded (e.g. the WooCommerce
 * shop). Where it is loaded, `.elementor-kit-N` (more specific than :root)
 * wins, so edits made in Elementor Site Settings still take effect.
 * Radius/spacing/container only exist here.
 */
final class TokenStyles
{
    public function register(): void
    {
        add_action('wp_head', [$this, 'print'], 5);
    }

    public function print(): void
    {
        $tokens = Settings::site()['tokens'] ?? null;

        if (! is_array($tokens)) {
            return;
        }

        $families = array_filter(array_unique([
            (string) ($tokens['fonts']['heading']['family'] ?? ''),
            (string) ($tokens['fonts']['body']['family'] ?? ''),
        ]));

        if ($families && ! did_action('elementor/loaded')) {
            $query = implode('&', array_map(fn ($f) => 'family='.str_replace(' ', '+', $f).':wght@400;600;700', $families));
            echo '<link rel="stylesheet" href="'.esc_url('https://fonts.googleapis.com/css2?'.$query.'&display=swap').'">'."\n";
        }

        // Values are validated hex colours and [A-Za-z0-9 -] font names (DesignTokensCss).
        echo '<style id="aisg-tokens">'.DesignTokensCss::toCss($tokens).'</style>'."\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}
