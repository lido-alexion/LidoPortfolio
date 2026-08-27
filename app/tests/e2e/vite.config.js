import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const here = path.dirname(fileURLToPath(import.meta.url));
const appRoot = path.resolve(here, '../..');

export default defineConfig({
    root: here,
    appType: 'spa',
    plugins: [react()],
    server: {
        host: '127.0.0.1',
        port: 4177,
        strictPort: true,
        fs: {
            allow: [appRoot],
        },
    },
});
