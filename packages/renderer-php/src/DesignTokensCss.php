<?php

namespace Aisg\Sections;

/**
 * Design tokens → CSS custom properties.
 *
 * Colours and fonts are written under Elementor's global variable names
 * (--e-global-color-*, --e-global-typography-*) because sections.css reads
 * those first. The platform preview and a WordPress site therefore feed the
 * same variables; in WordPress, Elementor's kit provides them itself.
 */
final class DesignTokensCss
{
    private const COLOR_VARIABLES = [
        'primary' => '--e-global-color-primary',
        'secondary' => '--e-global-color-secondary',
        'text' => '--e-global-color-text',
        'accent' => '--e-global-color-accent',
        'primary_contrast' => '--e-global-color-aisgprimarycontrast',
        'background' => '--e-global-color-aisgbackground',
        'surface' => '--e-global-color-aisgsurface',
        'muted' => '--e-global-color-aisgmuted',
        'border' => '--e-global-color-aisgborder',
    ];

    public const RADIUS = ['none' => '0px', 'sm' => '0.25rem', 'md' => '0.5rem', 'lg' => '1rem', 'full' => '1.5rem'];

    public const SPACING = ['compact' => '3rem', 'normal' => '5rem', 'airy' => '7rem'];

    /**
     * @param  array{colors?: array<string, string>, fonts?: array<string, array{family?: string}>, radius?: string, spacing?: string, container_width?: int}  $tokens
     * @return array<string, string>
     */
    public static function variables(array $tokens): array
    {
        $vars = [];

        foreach (self::COLOR_VARIABLES as $token => $variable) {
            $value = $tokens['colors'][$token] ?? null;

            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $vars[$variable] = strtolower($value);
            }
        }

        foreach (['heading' => 'primary', 'body' => 'text'] as $token => $slot) {
            $family = $tokens['fonts'][$token]['family'] ?? null;

            if (is_string($family) && preg_match('/^[A-Za-z0-9 \-]+$/', $family)) {
                $vars["--e-global-typography-{$slot}-font-family"] = "\"{$family}\"";
            }
        }

        $vars['--aisg-token-radius'] = self::RADIUS[$tokens['radius'] ?? 'md'] ?? self::RADIUS['md'];
        $vars['--aisg-token-space-section'] = self::SPACING[$tokens['spacing'] ?? 'normal'] ?? self::SPACING['normal'];
        $vars['--aisg-token-container'] = max(640, min(1920, (int) ($tokens['container_width'] ?? 1200))).'px';

        return $vars;
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    public static function toCss(array $tokens, string $selector = ':root'): string
    {
        $body = '';
        foreach (self::variables($tokens) as $name => $value) {
            $body .= "{$name}:{$value};";
        }

        return "{$selector}{{$body}}";
    }
}
