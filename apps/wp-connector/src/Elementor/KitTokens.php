<?php

namespace Aisg\Connector\Elementor;

/**
 * Design tokens → the active Elementor Kit (Site Settings › Global Colors /
 * Global Fonts, available in Elementor free). AISG sections read these via
 * --e-global-* variables, so editing them in Elementor restyles every section.
 *
 * System slots: primary, secondary, text, accent. The other AISG colours are
 * custom globals with fixed ids (aisgbackground → --e-global-color-aisgbackground).
 */
final class KitTokens
{
    private const CUSTOM_COLORS = [
        'primary_contrast' => ['aisgprimarycontrast', 'Button text'],
        'background' => ['aisgbackground', 'Page background'],
        'surface' => ['aisgsurface', 'Surface'],
        'muted' => ['aisgmuted', 'Muted text'],
        'border' => ['aisgborder', 'Borders'],
    ];

    /**
     * @param  array<string, mixed>  $tokens
     */
    public static function apply(array $tokens): bool
    {
        if (! did_action('elementor/loaded')) {
            return false;
        }

        $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();

        if (! $kit || ! $kit->get_id()) {
            return false;
        }

        $colors = $tokens['colors'] ?? [];
        $settings = $kit->get_settings();

        $settings['system_colors'] = [
            ['_id' => 'primary', 'title' => __('Primary', 'aisg-connector'), 'color' => $colors['primary'] ?? '#1f2937'],
            ['_id' => 'secondary', 'title' => __('Secondary', 'aisg-connector'), 'color' => $colors['secondary'] ?? '#6b7280'],
            ['_id' => 'text', 'title' => __('Text', 'aisg-connector'), 'color' => $colors['text'] ?? '#111827'],
            ['_id' => 'accent', 'title' => __('Accent', 'aisg-connector'), 'color' => $colors['accent'] ?? '#f59e0b'],
        ];

        // Keep the user's own custom colours; replace ours.
        $custom = array_values(array_filter(
            (array) ($settings['custom_colors'] ?? []),
            fn ($c) => ! str_starts_with((string) ($c['_id'] ?? ''), 'aisg'),
        ));
        foreach (self::CUSTOM_COLORS as $token => [$id, $title]) {
            if (! empty($colors[$token])) {
                $custom[] = ['_id' => $id, 'title' => 'AISG '.$title, 'color' => $colors[$token]];
            }
        }
        $settings['custom_colors'] = $custom;

        $heading = (string) ($tokens['fonts']['heading']['family'] ?? '');
        $body = (string) ($tokens['fonts']['body']['family'] ?? '');
        $font = fn (string $id, string $title, string $family, string $weight) => [
            '_id' => $id,
            'title' => $title,
            'typography_typography' => 'custom',
            'typography_font_family' => $family,
            'typography_font_weight' => $weight,
        ];
        $settings['system_typography'] = [
            $font('primary', __('Primary', 'aisg-connector'), $heading, '700'),
            $font('secondary', __('Secondary', 'aisg-connector'), $heading, '600'),
            $font('text', __('Text', 'aisg-connector'), $body, '400'),
            $font('accent', __('Accent', 'aisg-connector'), $body, '600'),
        ];

        $kit->save(['settings' => $settings]);

        return true;
    }
}
