<?php

namespace App\Enums;

enum CreationMode: string
{
    case Describe = 'describe';
    case ImportProducts = 'import_products';
    case ImportDesign = 'import_design';

    /**
     * Get the display label for the mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::Describe => 'Describe your store',
            self::ImportProducts => 'Import products',
            self::ImportDesign => 'Import a design',
        };
    }
}
