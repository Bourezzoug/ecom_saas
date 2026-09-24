<?php

namespace App\Enums;

enum PageType: string
{
    case Home = 'home';
    case About = 'about';
    case Contact = 'contact';
    case Faq = 'faq';
    case Landing = 'landing';
    case Custom = 'custom';

    /**
     * Page types the site planner may create (landing pages come from the product generator).
     *
     * @return list<string>
     */
    public static function plannable(): array
    {
        return [self::Home->value, self::About->value, self::Contact->value, self::Faq->value];
    }
}
