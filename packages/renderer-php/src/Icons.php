<?php

namespace Aisg\Sections;

/**
 * Icon names available to "icon" fields. Each maps to an .aisg-icon--{name}
 * rule in packages/section-styles/src/icons.css (CSS mask), so templates only
 * ever emit a class name, never markup.
 */
final class Icons
{
    public const NAMES = [
        'check', 'star', 'truck', 'shield', 'leaf', 'heart', 'gift', 'clock',
        'refresh', 'lock', 'sparkles', 'phone', 'mail', 'chat', 'tag', 'globe',
    ];
}
