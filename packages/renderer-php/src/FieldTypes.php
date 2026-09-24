<?php

namespace Aisg\Sections;

/**
 * The closed set of field types a section schema may use (docs/ARCHITECTURE.md §4.1).
 */
final class FieldTypes
{
    public const TEXT = 'text';

    public const TEXTAREA = 'textarea';

    public const NUMBER = 'number';

    public const BOOLEAN = 'boolean';

    public const SELECT = 'select';

    public const COLOR = 'color';

    public const LINK = 'link';

    public const IMAGE = 'image';

    public const ICON = 'icon';

    public const PRODUCT = 'product';

    public const PRODUCT_QUERY = 'product_query';

    public const CATEGORY = 'category';

    public const REPEATER = 'repeater';

    public const ALL = [
        self::TEXT, self::TEXTAREA, self::NUMBER, self::BOOLEAN, self::SELECT, self::COLOR, self::LINK,
        self::IMAGE, self::ICON, self::PRODUCT, self::PRODUCT_QUERY, self::CATEGORY, self::REPEATER,
    ];

    /**
     * Types the AI may fill. It never picks images, URLs, colours or products.
     */
    public const AI_GENERATABLE = [
        self::TEXT, self::TEXTAREA, self::NUMBER, self::BOOLEAN, self::SELECT, self::ICON, self::LINK, self::REPEATER,
    ];

    /**
     * Types allowed inside a repeater item (Elementor repeaters cannot nest).
     */
    public const REPEATER_ITEM = [
        self::TEXT, self::TEXTAREA, self::NUMBER, self::BOOLEAN, self::SELECT, self::COLOR, self::IMAGE, self::ICON, self::LINK,
    ];

    /**
     * Link target kinds.
     */
    public const LINK_TARGETS = ['page', 'system', 'product', 'url'];

    /**
     * System link targets resolvable on every platform.
     */
    public const SYSTEM_TARGETS = ['home', 'shop', 'cart', 'checkout', 'account'];
}
