import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { fileURLToPath } from 'node:url';
import { defineConfig, lazyPlugins } from 'vite-plus';

// Shared section renderer (packages/renderer-js): the browser twin of the PHP renderer.
const rendererPath = fileURLToPath(
    new URL('../../packages/renderer-js/src/index.ts', import.meta.url),
);

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        // No SSR: avoids a second (server) module graph being compiled in dev.
        inertia({ ssr: false }),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    resolve: {
        alias: { '@aisg/renderer': rendererPath },
        // One copy of mustache even though packages/renderer-js has its own node_modules.
        dedupe: ['mustache'],
    },
    server: {
        // The monorepo packages live outside this app's root.
        fs: { allow: ['.', '../../packages'] },
        // Inside Docker: listen on all interfaces, but let the browser reach HMR via localhost.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: 'localhost' },
        // Pre-transform every page at startup so the first click on a page isn't a cold compile
        // (each first transform is slow on a Windows bind mount).
        warmup: {
            clientFiles: [
                './resources/js/app.tsx',
                './resources/js/pages/**/*.tsx',
                './resources/js/components/**/*.tsx',
                './resources/js/layouts/**/*.tsx',
            ],
        },
        watch: {
            // Windows bind mounts don't emit inotify events into the container.
            usePolling: process.env.VITE_POLLING === 'true',
            interval: 1000,
            binaryInterval: 3000,
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
                // Constantly-changing or irrelevant dirs: polling them costs CPU for nothing.
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/tests/**',
                '**/database/**',
                '**/public/**',
            ],
        },
    },
    // Pre-bundle heavy dependencies up front so the first page load doesn't trigger a
    // dependency re-optimisation (and a full reload) halfway through.
    optimizeDeps: {
        include: [
            'react',
            'react-dom',
            'mustache',
            '@dnd-kit/core',
            '@dnd-kit/sortable',
            '@dnd-kit/utilities',
            'react-dom/client',
            'react/jsx-dev-runtime',
            '@inertiajs/react',
            '@laravel/echo-react',
            'laravel-echo',
            'pusher-js',
            'lucide-react',
            'sonner',
            'clsx',
            'tailwind-merge',
            'class-variance-authority',
            '@radix-ui/react-dialog',
            '@radix-ui/react-dropdown-menu',
            '@radix-ui/react-tooltip',
            '@radix-ui/react-toggle-group',
            '@radix-ui/react-slot',
            '@radix-ui/react-avatar',
            '@radix-ui/react-collapsible',
            '@radix-ui/react-separator',
            '@radix-ui/react-select',
            '@radix-ui/react-checkbox',
            '@radix-ui/react-label',
            '@radix-ui/react-navigation-menu',
        ],
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
