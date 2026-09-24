<?php

namespace Aisg\Connector\Elementor;

use Aisg\Connector\Plugin;
use Aisg\Connector\Sections\Library;
use Elementor\Elements_Manager;
use Elementor\Widgets_Manager;
use Throwable;

/**
 * Registers one AISG widget per section type, the widget category, and the
 * section runtime (compiled Tailwind CSS + Alpine) as dependencies.
 */
final class Widgets
{
    public const CATEGORY = 'aisg';

    public const STYLE_HANDLE = 'aisg-sections';

    public const RUNTIME_HANDLE = 'aisg-runtime';

    public const ALPINE_HANDLE = 'aisg-alpine';

    private static bool $registered = false;

    public function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('elementor/elements/categories_registered', [$this, 'category']);
        add_action('elementor/widgets/register', [$this, 'widgets']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets'], 5);
        add_action('elementor/frontend/after_register_scripts', [$this, 'registerAssets']);
        add_action('elementor/frontend/after_register_styles', [$this, 'registerAssets']);
        add_action('elementor/preview/enqueue_scripts', [$this, 'editorPreview']);
    }

    public function category(Elements_Manager $manager): void
    {
        $manager->add_category(self::CATEGORY, ['title' => __('AI Store sections', 'aisg-connector'), 'icon' => 'eicon-ai']);
    }

    public function widgets(Widgets_Manager $manager): void
    {
        try {
            $definitions = Library::registry()->all();
        } catch (Throwable $e) {
            error_log('[aisg] section library failed to load: '.$e->getMessage());

            return;
        }

        foreach ($definitions as $key => $definition) {
            $manager->register(new SectionWidget([], ['section_key' => $key]));
        }
    }

    public function registerAssets(): void
    {
        if (wp_style_is(self::STYLE_HANDLE, 'registered')) {
            return;
        }

        $version = AISG_CONNECTOR_VERSION.'-'.(string) @filemtime(Plugin::path('assets/runtime/sections.css'));

        wp_register_style(self::STYLE_HANDLE, Plugin::url('assets/runtime/sections.css'), [], $version);
        // Components must register on alpine:init, so the runtime loads before Alpine.
        wp_register_script(self::RUNTIME_HANDLE, Plugin::url('assets/runtime/aisg.js'), [], $version, false);
        wp_register_script(self::ALPINE_HANDLE, Plugin::url('assets/runtime/alpine.min.js'), [self::RUNTIME_HANDLE], '3', ['strategy' => 'defer', 'in_footer' => false]);
    }

    /**
     * Inside the Elementor editor preview, widgets re-render via AJAX: re-initialise Alpine on them.
     */
    public function editorPreview(): void
    {
        $this->registerAssets();
        wp_enqueue_style(self::STYLE_HANDLE);
        wp_enqueue_script(self::ALPINE_HANDLE);
        wp_enqueue_script('aisg-editor-preview', Plugin::url('assets/editor-preview.js'), ['jquery', self::ALPINE_HANDLE], AISG_CONNECTOR_VERSION, true);
    }
}
