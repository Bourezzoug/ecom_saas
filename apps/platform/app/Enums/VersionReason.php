<?php

namespace App\Enums;

enum VersionReason: string
{
    case Generation = 'generation';
    case Autosave = 'autosave';
    case Manual = 'manual';
    case PreRegenerate = 'pre_regenerate';
    case PrePublish = 'pre_publish';
    case PreRestore = 'pre_restore';

    public function label(): string
    {
        return match ($this) {
            self::Generation => 'AI generation',
            self::Autosave => 'Autosave',
            self::Manual => 'Saved version',
            self::PreRegenerate => 'Before AI rewrite',
            self::PrePublish => 'Before publish',
            self::PreRestore => 'Before restore',
        };
    }
}
