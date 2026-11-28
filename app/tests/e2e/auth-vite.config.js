import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const here = path.dirname(fileURLToPath(import.meta.url));
const appRoot = path.resolve(here, '../..');
const backend = process.env.STOX_AUTH_E2E_API || 'http://127.0.0.1:8001';

export default defineConfig({
    root: here,
    appType: 'spa',
    plugins: [react()],
    server: {
        host: '127.0.0.1',
        port: 4178,
        strictPort: true,
        fs: { allow: [appRoot] },
        proxy: {
            '/api': { target: backend, changeOrigin: false },
            '/sanctum': { target: backend, changeOrigin: false },
        },
    },
});
