<?php

namespace App\Domain\Design;

/**
 * Curated Google Fonts the planner may choose from, per writing system, so the
 * model can only pick fonts that exist and support the store's language.
 */
final class FontCatalog
{
    private const LATIN = [
        'Inter', 'DM Sans', 'Poppins', 'Montserrat', 'Work Sans', 'Nunito', 'Raleway',
        'Playfair Display', 'Lora', 'Merriweather', 'Cormorant Garamond', 'Space Grotesk',
    ];

    private const ARABIC = ['Cairo', 'Tajawal', 'Almarai', 'IBM Plex Sans Arabic', 'Noto Kufi Arabic', 'Amiri'];

    private const HEBREW = ['Heebo', 'Rubik', 'Assistant', 'Frank Ruhl Libre'];

    private const PERSIAN = ['Vazirmatn', 'Noto Naskh Arabic', 'IBM Plex Sans Arabic'];

    /**
     * @return list<string>
     */
    public static function forLanguage(string $language): array
    {
        return match ($language) {
            'ar' => self::ARABIC,
            'he' => self::HEBREW,
            'fa', 'ur' => self::PERSIAN,
            default => self::LATIN,
        };
    }

    public static function default(string $language): string
    {
        return self::forLanguage($language)[0];
    }

    /**
     * Google Fonts css2 URL for the given families (regular + bold weights).
     *
     * @param  list<string>  $families
     */
    public static function googleFontsUrl(array $families): string
    {
        $query = collect($families)
            ->filter()
            ->unique()
            ->map(fn (string $family) => 'family='.str_replace(' ', '+', $family).':wght@400;600;700')
            ->implode('&');

        return "https://fonts.googleapis.com/css2?{$query}&display=swap";
    }
}
