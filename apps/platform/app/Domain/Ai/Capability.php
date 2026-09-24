<?php

namespace App\Domain\Ai;

enum Capability: string
{
    case Json = 'json';
    case Text = 'text';
    case Vision = 'vision';
}
