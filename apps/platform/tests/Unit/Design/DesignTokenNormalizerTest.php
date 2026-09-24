<?php

use App\Domain\Design\Color;
use App\Domain\Design\DesignTokenNormalizer;

test('brand colours win and missing values fall back to defaults', function () {
    $tokens = (new DesignTokenNormalizer)->normalize(['primary' => '#ff0000', 'accent' => 'not-a-colour'], ['#7C4A1E', '#abc'], 'en');

    expect($tokens['colors']['primary'])->toBe('#7c4a1e')
        ->and($tokens['colors']['secondary'])->toBe('#aabbcc')
        ->and($tokens['colors']['accent'])->toBe('#f59e0b')
        ->and($tokens['radius'])->toBe('md')
        ->and($tokens['spacing'])->toBe('normal')
        ->and($tokens['fonts']['heading']['family'])->toBe('Inter');
});

test('unreadable text is replaced so body copy always reaches 7:1', function () {
    $tokens = (new DesignTokenNormalizer)->normalize(['background' => '#f0e0d0', 'text' => '#d0c0b0'], [], 'en');

    expect(Color::contrast($tokens['colors']['text'], $tokens['colors']['background']))->toBeGreaterThanOrEqual(7);
});

test('a genuinely dark theme is kept with light text', function () {
    $tokens = (new DesignTokenNormalizer)->normalize(['background' => '#101418', 'text' => '#303030'], [], 'en');

    expect($tokens['colors']['background'])->toBe('#101418')
        ->and(Color::contrast($tokens['colors']['text'], '#101418'))->toBeGreaterThanOrEqual(7);
});

test('button text is readable on the primary colour', function (string $primary, string $expected) {
    expect((new DesignTokenNormalizer)->normalize(['primary' => $primary], [], 'en')['colors']['primary_contrast'])->toBe($expected);
})->with([
    ['#1f2937', '#ffffff'],
    ['#fde68a', '#111827'],
]);

test('fonts must come from the catalog of the store language', function () {
    $ar = (new DesignTokenNormalizer)->normalize(['heading_font' => 'Tajawal', 'body_font' => 'Inter'], [], 'ar');

    expect($ar['fonts']['heading']['family'])->toBe('Tajawal')
        ->and($ar['fonts']['body']['family'])->toBe('Cairo');
});
