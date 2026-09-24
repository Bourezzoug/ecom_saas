<?php

namespace App\Enums;

/**
 * Copy tones the generator understands (kept to a short list for small models).
 */
enum BrandTone: string
{
    case Friendly = 'friendly';
    case Premium = 'premium';
    case Playful = 'playful';
    case Bold = 'bold';
    case Minimal = 'minimal';
    case Professional = 'professional';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
