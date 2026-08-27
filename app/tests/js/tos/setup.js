import { afterEach, beforeEach, vi } from 'vitest';
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { apiMock, resetApiMock } from './helpers/mockApi.js';

vi.mock('../../../resources/js/src/api', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        default: apiMock,
    };
});

vi.mock('../../../resources/js/src/auth/csrf', () => ({
    ensureCsrfCookie: vi.fn(async () => {}),
    resetCsrfCookie: vi.fn(),
    getRequestCsrfToken: vi.fn(() => 'test-csrf'),
    isPlainCsrfToken: vi.fn(() => true),
    readCsrfToken: vi.fn(() => 'test-csrf'),
}));

beforeEach(() => {
    resetApiMock();
    window.localStorage.clear();
    window.sessionStorage.clear();
    window.matchMedia = vi.fn().mockImplementation((query) => ({
        matches: String(query).includes('min-width: 1200px'),
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }));
});

afterEach(() => {
    cleanup();
});
