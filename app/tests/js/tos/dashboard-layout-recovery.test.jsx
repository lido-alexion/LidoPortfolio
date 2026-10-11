import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DashboardLayoutToolbar from '../../../resources/js/src/components/DashboardLayoutToolbar.jsx';
import { apiMock } from './helpers/mockApi.js';
import { factoryDashboardLayout } from '../../../resources/js/src/utils/dashboardLayouts.js';

vi.mock('../../../resources/js/src/components/ExportDataButton.jsx', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/ExportBasketPanel.jsx', () => ({ default: () => null }));

const record = () => ({ id: 7, name: 'Investor', is_default: false, definition: factoryDashboardLayout() });

describe('dashboard layout recovery', () => {
    beforeEach(() => {
        cleanup();
        vi.clearAllMocks();
        window.localStorage.clear();
    });

    it('distinguishes an unavailable named-layout list and retries without claiming it is empty', async () => {
        apiMock.get.mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValueOnce({ data: { data: [record()] } });
        render(<DashboardLayoutToolbar userId={1} onLayoutChange={vi.fn()} />);
        expect(await screen.findByRole('alert')).toHaveTextContent('Named dashboards could not be loaded');
        expect(screen.queryByLabelText('Named dashboard')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Retry named dashboards' }));
        const select = await screen.findByLabelText('Named dashboard');
        expect(select).toHaveTextContent('Investor');
        expect(apiMock.get).toHaveBeenCalledTimes(2);
    });

    it('announces a failed default change and keeps the reviewed record selected for retry', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [record()] } });
        apiMock.put.mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValueOnce({ data: { data: { ...record(), is_default: true } } });
        render(<DashboardLayoutToolbar userId={1} onLayoutChange={vi.fn()} />);
        fireEvent.change(await screen.findByLabelText('Named dashboard'), { target: { value: '7' } });
        fireEvent.click(screen.getByRole('button', { name: 'Set default' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Default dashboard could not be confirmed');
        expect(screen.getByLabelText('Named dashboard')).toHaveValue('7');
        fireEvent.click(screen.getByRole('button', { name: 'Set default' }));
        await vi.waitFor(() => expect(screen.getByLabelText('Named dashboard')).toHaveTextContent('Investor (default)'));
        expect(apiMock.put).toHaveBeenCalledTimes(2);
    });

    it('offers an explicit retry after a named autosave fails', async () => {
        apiMock.get.mockResolvedValue({ data: { data: [record()] } });
        apiMock.put.mockRejectedValueOnce({ response: { status: 503 } }).mockImplementationOnce(async (path, body) => ({ data: { data: { ...record(), definition: body.definition } } }));
        render(<DashboardLayoutToolbar userId={1} onLayoutChange={vi.fn()} />);
        fireEvent.change(await screen.findByLabelText('Named dashboard'), { target: { value: '7' } });
        fireEvent.click(screen.getByRole('button', { name: 'Customize' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'XIRR' }));
        expect(await screen.findByText('Failed', {}, { timeout: 2000 })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Retry named dashboard save' }));
        expect(await screen.findByText('Saved', {}, { timeout: 2000 })).toBeVisible();
        expect(apiMock.put).toHaveBeenCalledTimes(2);
    });
});
