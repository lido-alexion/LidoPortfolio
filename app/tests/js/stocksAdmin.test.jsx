import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { MemoryRouter } from 'react-router-dom';
import { render } from '@testing-library/react';
import StocksAdminPage from '../../resources/js/src/pages/StocksAdminPage.jsx';

const apiGet = vi.fn();
const apiPost = vi.fn();

vi.mock('../../resources/js/src/api.js', () => ({
    default: {
        get: (...args) => apiGet(...args),
        post: (...args) => apiPost(...args),
    },
}));

vi.mock('../../resources/js/src/toast.js', () => ({
    showToast: vi.fn(),
}));

function renderPage() {
    return render(
        <MemoryRouter>
            <StocksAdminPage />
        </MemoryRouter>,
    );
}

const sampleStock = {
    id: 1,
    symbol: 'INFY',
    name: 'Infosys Ltd',
    exchange: 'NSE',
    exchange_label: 'NSE+',
    sector: 'IT',
    is_active: true,
    admin_deactivated: false,
    effective_active: true,
};

const inactiveStock = {
    id: 2,
    symbol: 'OLDCO',
    name: 'Old Co',
    exchange: 'NSE',
    exchange_label: 'NSE',
    sector: null,
    is_active: false,
    admin_deactivated: false,
    effective_active: false,
};

const adminOffStock = {
    id: 3,
    symbol: 'BLOCK',
    name: 'Blocked Co',
    exchange: 'NSE',
    exchange_label: 'NSE',
    sector: 'Finance',
    is_active: true,
    admin_deactivated: true,
    effective_active: false,
};

function mockCatalogueResponse(rows, overrides = {}) {
    apiGet.mockResolvedValueOnce({
        data: {
            data: rows,
            current_page: overrides.current_page ?? 1,
            last_page: overrides.last_page ?? 1,
            per_page: overrides.per_page ?? 25,
            from: overrides.from ?? (rows.length ? 1 : null),
            to: overrides.to ?? rows.length,
            total: overrides.total ?? rows.length,
        },
    });
}

describe('StocksAdminPage (V4-FEAT-011)', () => {
    beforeEach(() => {
        apiGet.mockReset();
        apiPost.mockReset();
    });

    it('renders catalogue and loads admin stocks', async () => {
        mockCatalogueResponse([sampleStock, inactiveStock, adminOffStock]);
        renderPage();

        expect(await screen.findByTestId('stocks-admin-page')).toBeInTheDocument();
        expect(screen.getByText('INFY')).toBeInTheDocument();
        expect(screen.getByText('OLDCO')).toBeInTheDocument();
        expect(screen.getByText('BLOCK')).toBeInTheDocument();

        expect(apiGet).toHaveBeenCalledWith('/admin/stocks', {
            params: { page: 1, per_page: 25, q: undefined, status: 'all' },
        });
    });

    it('debounces search input', async () => {
        vi.useFakeTimers();
        mockCatalogueResponse([sampleStock]);
        renderPage();
        await screen.findByText('INFY');

        apiGet.mockClear();
        mockCatalogueResponse([sampleStock]);

        fireEvent.change(screen.getByTestId('stocks-admin-search'), { target: { value: 'inf' } });
        vi.advanceTimersByTime(299);
        expect(apiGet).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        await waitFor(() => {
            expect(apiGet).toHaveBeenCalledWith('/admin/stocks', {
                params: { page: 1, per_page: 25, q: 'inf', status: 'all' },
            });
        });
        vi.useRealTimers();
    });

    it('changes status filter', async () => {
        const user = userEvent.setup();
        mockCatalogueResponse([adminOffStock]);
        renderPage();
        await screen.findByText('BLOCK');

        apiGet.mockClear();
        mockCatalogueResponse([adminOffStock]);

        await user.selectOptions(screen.getByTestId('stocks-admin-status-filter'), 'admin_deactivated');

        await waitFor(() => {
            expect(apiGet).toHaveBeenCalledWith('/admin/stocks', {
                params: { page: 1, per_page: 25, q: undefined, status: 'admin_deactivated' },
            });
        });
    });

    it('shows system, admin override, and effective status badges', async () => {
        mockCatalogueResponse([adminOffStock]);
        renderPage();
        await screen.findByText('BLOCK');

        expect(screen.getByText('In feed')).toBeInTheDocument();
        expect(screen.getByText('Deactivated')).toBeInTheDocument();
        expect(screen.getByText('Unavailable')).toBeInTheDocument();
    });

    it('deactivates an active stock', async () => {
        const user = userEvent.setup();
        mockCatalogueResponse([sampleStock]);
        renderPage();
        await screen.findByText('INFY');

        window.confirm = vi.fn(() => true);
        apiPost.mockResolvedValueOnce({ data: { data: { ...sampleStock, admin_deactivated: true } } });
        mockCatalogueResponse([{ ...sampleStock, admin_deactivated: true, effective_active: false }]);

        await user.click(screen.getByRole('button', { name: 'Deactivate' }));

        await waitFor(() => {
            expect(apiPost).toHaveBeenCalledWith('/stocks/1/deactivate');
        });
    });

    it('activates an admin-deactivated stock', async () => {
        const user = userEvent.setup();
        mockCatalogueResponse([adminOffStock]);
        renderPage();
        await screen.findByText('BLOCK');

        apiPost.mockResolvedValueOnce({ data: { data: { ...adminOffStock, admin_deactivated: false, effective_active: true } } });
        mockCatalogueResponse([{ ...adminOffStock, admin_deactivated: false, effective_active: true }]);

        await user.click(screen.getByRole('button', { name: 'Activate' }));

        await waitFor(() => {
            expect(apiPost).toHaveBeenCalledWith('/stocks/3/activate');
        });
    });

    it('does not expose manual add or delete controls', async () => {
        mockCatalogueResponse([sampleStock]);
        renderPage();
        await screen.findByText('INFY');

        expect(screen.queryByRole('button', { name: /add stock/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /create/i })).not.toBeInTheDocument();
    });
});
