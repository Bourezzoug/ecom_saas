<?php

namespace App\Domain\Design;

/**
 * Small colour helpers for keeping AI-picked palettes readable (WCAG contrast).
 */
final class Color
{
    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * Accepts #abc / #aabbcc (any case); returns lowercase #aabbcc or null.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
            return "#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}";
        }

        return self::isHex($value) ? $value : null;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    public static function luminance(string $hex): float
    {
        $channels = array_map(function (int $c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Mix $b into $a by $weight (0 = a, 1 = b).
     */
    public static function mix(string $a, string $b, float $weight): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);

        $channel = fn (int $x, int $y) => str_pad(dechex((int) round($x + ($y - $x) * $weight)), 2, '0', STR_PAD_LEFT);

        return '#'.$channel($r1, $r2).$channel($g1, $g2).$channel($b1, $b2);
    }

    /**
     * White or near-black, whichever reads better on $background.
     */
    public static function readableOn(string $background, string $dark = '#111827', string $light = '#ffffff'): string
    {
        return self::contrast($background, $light) >= self::contrast($background, $dark) ? $light : $dark;
    }
}
