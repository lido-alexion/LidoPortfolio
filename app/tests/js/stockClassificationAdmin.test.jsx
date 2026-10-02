import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import StockClassificationAdminPage from '../../resources/js/src/pages/StockClassificationAdminPage.jsx';
import { apiMock, axiosError } from './tos/helpers/mockApi.js';

vi.mock('../../resources/js/src/toast.js', () => ({ showToast: vi.fn() }));

const taxonomy = {
    sectors: ['Information Technology', 'Financial Services'],
    industries: { 'Information Technology': ['IT Services'], 'Financial Services': ['Banks'] },
};

const automatic = { source_type: 'unknown', source: null, sector: null, industry: null, observed_at: null };
const tcs = { id: 1, symbol: 'TCS', name: 'TCS Limited', classification: automatic };
const infy = { id: 2, symbol: 'INFY', name: 'Infosys Limited', classification: automatic };

function renderPage(rows = [tcs, infy]) {
    apiMock.get.mockImplementation((url) => String(url).includes('/options')
        ? Promise.resolve({ data: { data: taxonomy } })
        : Promise.resolve({ data: { data: rows } }));
    return render(<StockClassificationAdminPage />);
}

describe('StockClassificationAdminPage', () => {
    it('synchronizes search row, selected details and form after Save', async () => {
        apiMock.put.mockResolvedValue({ data: { data: { source_type: 'manual_override', source: 'admin', sector: 'Information Technology', industry: 'IT Services', observed_at: '2026-10-02T12:00:00Z' } } });
        renderPage();
        await screen.findByText('TCS · TCS Limited');
        fireEvent.click(screen.getByRole('button', { name: /TCS · TCS Limited/ }));
        fireEvent.change(screen.getByLabelText('Sector'), { target: { value: 'Information Technology' } });
        fireEvent.change(screen.getByLabelText('Industry'), { target: { value: 'IT Services' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(screen.getByText('Information Technology / IT Services')).toBeInTheDocument());
        expect(screen.getByText('Current source: admin')).toBeInTheDocument();
        expect(screen.getByLabelText('Sector')).toHaveValue('Information Technology');
        expect(screen.getByLabelText('Industry')).toHaveValue('IT Services');
    });

    it('resets search row and selectors after Return to automatic', async () => {
        const overridden = { ...tcs, classification: { source_type: 'manual_override', source: 'admin', sector: 'Information Technology', industry: 'IT Services' } };
        apiMock.delete.mockResolvedValue({ data: { data: automatic } });
        renderPage([overridden, infy]);
        fireEvent.click(await screen.findByRole('button', { name: /TCS · TCS Limited/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Return to automatic' }));

        await waitFor(() => expect(screen.getByText('Unknown / Unknown')).toBeInTheDocument());
        expect(screen.getByText('Current source: unknown')).toBeInTheDocument();
        expect(screen.getByLabelText('Sector')).toHaveValue('');
        expect(screen.getByLabelText('Industry')).toHaveValue('');
    });

    it('loads the next stock draft when switching stocks', async () => {
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: /TCS · TCS Limited/ }));
        fireEvent.change(screen.getByLabelText('Sector'), { target: { value: 'Information Technology' } });
        fireEvent.change(screen.getByLabelText('Industry'), { target: { value: 'IT Services' } });
        fireEvent.click(screen.getByRole('button', { name: /INFY · Infosys Limited/ }));
        expect(screen.getByLabelText('Sector')).toHaveValue('');
        expect(screen.getByLabelText('Industry')).toHaveValue('');
    });

    it('keeps draft values and displays failed mutation errors', async () => {
        apiMock.put.mockRejectedValue(axiosError('Provider unavailable', { body: { message: 'Override rejected by server' } }));
        renderPage();
        fireEvent.click(await screen.findByRole('button', { name: /TCS · TCS Limited/ }));
        fireEvent.change(screen.getByLabelText('Sector'), { target: { value: 'Information Technology' } });
        fireEvent.change(screen.getByLabelText('Industry'), { target: { value: 'IT Services' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Override rejected by server');
        expect(screen.getByLabelText('Sector')).toHaveValue('Information Technology');
        expect(screen.getByLabelText('Industry')).toHaveValue('IT Services');
    });

    it('shows the persisted server classification after reload', async () => {
        let persisted = false;
        const saved = { source_type: 'manual_override', source: 'admin', sector: 'Information Technology', industry: 'IT Services', observed_at: '2026-10-02T12:00:00Z' };
        apiMock.get.mockImplementation((url) => String(url).includes('/options')
            ? Promise.resolve({ data: { data: taxonomy } })
            : Promise.resolve({ data: { data: [persisted ? { ...tcs, classification: saved } : tcs, infy] } }));
        apiMock.put.mockImplementation(async () => {
            persisted = true;
            return { data: { data: saved } };
        });

        const first = render(<StockClassificationAdminPage />);
        fireEvent.click(await screen.findByRole('button', { name: /TCS · TCS Limited/ }));
        fireEvent.change(screen.getByLabelText('Sector'), { target: { value: 'Information Technology' } });
        fireEvent.change(screen.getByLabelText('Industry'), { target: { value: 'IT Services' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(screen.getByText('Current source: admin')).toBeInTheDocument());

        first.unmount();
        render(<StockClassificationAdminPage />);
        fireEvent.click(await screen.findByRole('button', { name: /TCS · TCS Limited/ }));
        expect(screen.getByText('Information Technology / IT Services')).toBeInTheDocument();
        expect(screen.getByLabelText('Sector')).toHaveValue('Information Technology');
        expect(screen.getByLabelText('Industry')).toHaveValue('IT Services');
    });
});
