import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { fontsource } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
                'resources/css/marketing.css',
                'resources/js/marketing.js',
            ],
            refresh: true,
            // The platform theme picks one of these (PLATFORM_CONSOLE.md § 9.2);
            // pages load only the chosen family through @fonts('alias').
            fonts: [
                fontsource('Inter', { weights: [400, 500, 600, 700, 800] }),
                fontsource('Plus Jakarta Sans', { weights: [400, 500, 600, 700, 800] }),
                fontsource('Manrope', { weights: [400, 500, 600, 700, 800] }),
                fontsource('DM Sans', { weights: [400, 500, 600, 700, 800] }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
