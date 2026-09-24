<?php

namespace App\Domain\Design;

/**
 * Turns the planner's design suggestion (+ the user's brand colours) into a
 * complete, readable token set. Small models pick poor palettes, so every
 * colour is checked and repaired rather than trusted:
 *
 *  - brand colours from the brief win for primary/secondary
 *  - invalid hex values fall back to defaults
 *  - text must reach 7:1 on the background, otherwise a safe light theme is used
 *  - surface, muted, border and primary_contrast are derived, not asked for
 */
final class DesignTokenNormalizer
{
    public const DEFAULTS = [
        'primary' => '#1f2937',
        'secondary' => '#6b7280',
        'accent' => '#f59e0b',
        'background' => '#ffffff',
        'text' => '#111827',
    ];

    public const RADII = ['none', 'sm', 'md', 'lg', 'full'];

    public const SPACINGS = ['compact', 'normal', 'airy'];

    /**
     * @param  array<string, mixed>  $design  Planner output "design" object.
     * @param  list<string>  $brandColors
     * @param  bool  $enforceContrast  True for AI suggestions; false for colours a person picked in the editor.
     * @return array{colors: array<string, string>, fonts: array{heading: array{family: string}, body: array{family: string}}, radius: string, spacing: string, container_width: int}
     */
    public function normalize(array $design, array $brandColors, string $language, bool $enforceContrast = true): array
    {
        $pick = fn (string $key, ?string $brand = null) => Color::normalize($brand) ?? Color::normalize($design[$key] ?? null) ?? self::DEFAULTS[$key];

        $primary = $pick('primary', $brandColors[0] ?? null);
        $secondary = $pick('secondary', $brandColors[1] ?? null);
        $accent = $pick('accent', $brandColors[2] ?? null);
        $background = $pick('background');
        $text = $pick('text');

        if ($enforceContrast && Color::contrast($text, $background) < 7) {
            // Keep a dark theme only when it is genuinely readable; otherwise go light.
            $background = Color::luminance($background) < 0.2 ? $background : self::DEFAULTS['background'];
            $text = Color::readableOn($background, self::DEFAULTS['text'], '#f9fafb');
        }

        $surface = Color::mix($background, $primary, 0.06);
        $muted = Color::mix($text, $background, 0.35);
        $border = Color::mix($background, $text, 0.14);

        $fonts = FontCatalog::forLanguage($language);
        $fontPick = fn (string $key) => in_array($design[$key] ?? null, $fonts, true) ? $design[$key] : $fonts[0];

        return [
            'colors' => [
                'primary' => $primary,
                'primary_contrast' => Color::readableOn($primary),
                'secondary' => $secondary,
                'accent' => $accent,
                'background' => $background,
                'surface' => $surface,
                'text' => $text,
                'muted' => $muted,
                'border' => $border,
            ],
            'fonts' => [
                'heading' => ['family' => $fontPick('heading_font')],
                'body' => ['family' => $fontPick('body_font')],
            ],
            'radius' => in_array($design['radius'] ?? null, self::RADII, true) ? $design['radius'] : 'md',
            'spacing' => in_array($design['spacing'] ?? null, self::SPACINGS, true) ? $design['spacing'] : 'normal',
            'container_width' => 1200,
        ];
    }
}
