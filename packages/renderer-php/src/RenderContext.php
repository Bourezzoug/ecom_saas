<?php

namespace Aisg\Sections;

use Closure;

/**
 * Everything a section needs besides its own content: site-level values and
 * how link targets become URLs on the current platform (preview, WordPress...).
 */
final class RenderContext
{
    /**
     * @param  array{name?: string, lang?: string, dir?: string, currency?: string|null, year?: int}  $site
     * @param  (Closure(array{kind: string, value: string|null}): ?string)|null  $linkResolver
     *                                                                                          Returns a URL for page/system/product targets, or null for "#".
     */
    public function __construct(
        public readonly array $site = [],
        private readonly ?Closure $linkResolver = null,
    ) {}

    /**
     * @return array{name: string, lang: string, dir: string, currency: string, year: int}
     */
    public function siteViewModel(): array
    {
        return [
            'name' => (string) ($this->site['name'] ?? ''),
            'lang' => (string) ($this->site['lang'] ?? 'en'),
            'dir' => ($this->site['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
            'currency' => (string) ($this->site['currency'] ?? ''),
            'year' => (int) ($this->site['year'] ?? (int) date('Y')),
        ];
    }

    /**
     * @param  array{kind?: string, value?: string|null}  $target
     */
    public function resolveHref(array $target): string
    {
        $kind = $target['kind'] ?? 'url';
        $value = $target['value'] ?? null;

        if ($kind === 'url') {
            return self::safeUrl($value);
        }

        $resolved = $this->linkResolver !== null
            ? ($this->linkResolver)(['kind' => $kind, 'value' => $value])
            : null;

        return $resolved !== null ? self::safeUrl($resolved) : '#';
    }

    /**
     * Only http(s), mailto:, tel:, relative paths and fragments survive; anything
     * else (javascript:, data:...) becomes "#".
     */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '#';
        }

        if (preg_match('~^(https?://|mailto:|tel:|/(?!/)|#|\?)~i', $url)) {
            return $url;
        }

        return '#';
    }
}
