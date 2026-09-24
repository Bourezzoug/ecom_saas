<?php

namespace App\Enums;

/**
 * v1 only generates WooCommerce stores; business sites are a future kind.
 */
enum ProjectKind: string
{
    case Store = 'store';
}
