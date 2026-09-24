/**
 * Section CSS (docs/ARCHITECTURE.md §4.5).
 *
 * - v3.4 on purpose: its output is unlayered, and `important: '.aisg'` scopes every
 *   utility as `.aisg .tw-x`, which outranks typical theme/Elementor/WooCommerce rules
 *   without !important. (v4 emits @layer utilities, which unlayered theme CSS beats.)
 * - No preflight: we must not reset the host site's styles.
 * - Design tokens are CSS variables; the AI never adds classes.
 */
/** @type {import('tailwindcss').Config} */
module.exports = {
    prefix: 'tw-',
    important: '.aisg',
    corePlugins: {
        preflight: false,
    },
    content: ['../../sections/**/*.mustache'],
    theme: {
        extend: {
            colors: {
                primary: 'var(--aisg-color-primary)',
                'primary-contrast': 'var(--aisg-color-primary-contrast)',
                secondary: 'var(--aisg-color-secondary)',
                accent: 'var(--aisg-color-accent)',
                background: 'var(--aisg-color-background)',
                surface: 'var(--aisg-color-surface)',
                text: 'var(--aisg-color-text)',
                muted: 'var(--aisg-color-muted)',
                border: 'var(--aisg-color-border)',
            },
            fontFamily: {
                heading: 'var(--aisg-font-heading)',
                body: 'var(--aisg-font-body)',
            },
            borderRadius: {
                aisg: 'var(--aisg-radius)',
            },
            maxWidth: {
                container: 'var(--aisg-container)',
            },
            spacing: {
                section: 'var(--aisg-space-section)',
            },
        },
    },
    plugins: [],
};
