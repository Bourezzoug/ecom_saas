<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Grant = 'grant';
    case Purchase = 'purchase';
    case Spend = 'spend';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
    case Expire = 'expire';
}
