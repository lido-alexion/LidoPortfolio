import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

const PRODUCTION_DEFAULT_BASE = '/portfolio/build/';

export default defineConfig(({ mode }) => {
    const envBase = process.env.VITE_APP_BASE;
    const appBase = (envBase ?? (mode === 'production' ? PRODUCTION_DEFAULT_BASE : '/')).replace(/\/?$/, '/');

    return {
        base: appBase === '/' ? '/' : appBase,
        plugins: [
            react(),
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/src/styles/lido-app.css',
                    'resources/js/app.jsx',
                ],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        server: {
            host: '127.0.0.1',
            port: 5173,
            strictPort: true,
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
        build: {
            target: ['es2020', 'safari14', 'chrome87', 'firefox78'],
        },
        experimental: {
            // Font + image urls in lido-app.css must resolve next to the built CSS file
            // (/portfolio/build/assets/), not as site-root /assets/ (404 on subdirectory deploy).
            renderBuiltUrl(filename, { hostType }) {
                if (hostType === 'css') {
                    return { relative: true };
                }

                return undefined;
            },
        },
    };
});
