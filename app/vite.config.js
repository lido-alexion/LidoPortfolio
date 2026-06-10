import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

const appBase = (process.env.VITE_APP_BASE || '/').replace(/\/?$/, '/');

export default defineConfig({
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
});
