<?php

namespace Aisg\Sections;

/**
 * Renders section templates with mustache.php, configured to match the JS
 * renderer exactly (same escape function, no lambdas, no partials).
 */
final class Renderer
{
    /** @var \Mustache_Engine|\Mustache\Engine */
    private object $engine;

    public function __construct()
    {
        $options = [
            'escape' => [self::class, 'escape'],
            'strict_callables' => true,
            'pragmas' => [],
        ];

        $this->engine = class_exists(\Mustache\Engine::class)
            ? new \Mustache\Engine($options)
            : new \Mustache_Engine($options);
    }

    /**
     * The one escape function both runtimes use: & < > " ' → &amp; &lt; &gt; &quot; &#039;
     * (mustache.php and mustache.js disagree by default, see §4.4.)
     */
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $viewModel
     */
    public function render(SectionDefinition $definition, array $viewModel): string
    {
        return $this->engine->render($definition->template, $viewModel);
    }

    /**
     * The .aisg wrapper that scopes styles and carries direction/language.
     *
     * @param  array{lang: string, dir: string}  $site
     */
    public static function wrap(string $html, array $site, string $extraAttributes = ''): string
    {
        return sprintf(
            '<div class="aisg" dir="%s" lang="%s"%s>%s</div>',
            self::escape($site['dir']),
            self::escape($site['lang']),
            $extraAttributes !== '' ? ' '.$extraAttributes : '',
            $html,
        );
    }
}
