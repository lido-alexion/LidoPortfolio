import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        setupFiles: ['tests/js/tos/setup.js'],
        include: [
            'tests/js/tos/**/*.test.{js,jsx}',
            'tests/js/stocksAdmin.test.jsx',
        ],
        css: false,
        restoreMocks: false,
        clearMocks: false,
        testTimeout: 20000,
        hookTimeout: 20000,
        fileParallelism: false,
    },
});
