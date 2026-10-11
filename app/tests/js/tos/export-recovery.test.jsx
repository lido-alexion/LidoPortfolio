import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ExportDataButton from '../../../resources/js/src/components/ExportDataButton.jsx';
import ExportBasketPanel from '../../../resources/js/src/components/ExportBasketPanel.jsx';
import ExportArtifactStatus from '../../../resources/js/src/components/ExportArtifactStatus.jsx';
import { apiMock } from './helpers/mockApi.js';

const token = '11111111-1111-4111-8111-111111111111';
const queued = { token, status: 'queued', download_url: `/api/exports/${token}/download` };
const item = { dataset: 'portfolio-growth', sheet_name: 'Growth', fields: ['snapshot_date'], scope: 'full' };
const catalog = [{ id: item.dataset, label: 'Growth data', fields: item.fields, scopes: ['full'] }];
afterEach(() => vi.restoreAllMocks());
beforeEach(() => {
    vi.spyOn(window, 'prompt').mockImplementation(message => message.includes('format') ? 'csv' : 'full');
    apiMock.get.mockImplementation(async path => ({ data: { data: path.endsWith('/catalog') ? catalog : { items: [item] } } }));
});

it('exposes status and cancellation after a queued single-dataset export', async () => {
    apiMock.post.mockResolvedValue({ data: { data: queued } });
    render(<ExportDataButton dataset="portfolio-growth" />);
    await userEvent.click(screen.getByRole('button', { name: 'Export data', exact: true }));
    expect(await screen.findByRole('button', { name: 'Refresh export status', exact: true })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Cancel export', exact: true })).toBeVisible();
    expect(screen.queryByRole('link', { name: 'Download export' })).not.toBeInTheDocument();
});

it('keeps the saved basket item when removal fails', async () => {
    apiMock.put.mockRejectedValue({ response: { data: { message: 'Save unavailable.' } } });
    render(<ExportBasketPanel />);
    await userEvent.click(screen.getByRole('button', { name: 'Export basket (0)' }));
    await screen.findByRole('region', { name: 'Configuration for Growth data' });
    await userEvent.click(screen.getByRole('button', { name: 'Remove', exact: true }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalled());
    expect(screen.getByRole('button', { name: 'Export basket (1)' })).toBeVisible();
});

it('does not show a new basket item as saved when addition fails', async () => {
    apiMock.get.mockImplementation(async path => ({ data: { data: path.endsWith('/catalog') ? catalog : { items: [] } } }));
    apiMock.put.mockRejectedValue({ response: { data: { message: 'Save unavailable.' } } });
    render(<ExportBasketPanel />);
    await userEvent.click(screen.getByRole('button', { name: 'Export basket (0)' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Add Growth data' }));
    await waitFor(() => expect(apiMock.put).toHaveBeenCalled());
    expect(screen.getByRole('button', { name: 'Export basket (0)' })).toBeVisible();
});

it('does not navigate to a download while a basket export is queued', async () => {
    apiMock.put.mockResolvedValue({ data: { data: { items: [item] } } });
    apiMock.post.mockResolvedValue({ data: { data: queued } });
    render(<ExportBasketPanel />);
    await userEvent.click(screen.getByRole('button', { name: 'Export basket (0)' }));
    await screen.findByRole('region', { name: 'Configuration for Growth data' });
    await userEvent.click(screen.getByRole('button', { name: 'Export XLSX' }));
    expect(await screen.findByRole('button', { name: 'Cancel export', exact: true })).toBeVisible();
    expect(screen.queryByRole('link', { name: 'Download export' })).not.toBeInTheDocument();
});

it('retains a queued export after a failed read, then exposes only a ready unexpired download', async () => {
    apiMock.get.mockRejectedValueOnce(new Error('Read unavailable.')).mockResolvedValueOnce({ data: { data: { ...queued, status: 'ready', expires_at: '2099-01-01T00:00:00Z' } } });
    render(<ExportArtifactStatus artifact={queued} />);
    await userEvent.click(screen.getByRole('button', { name: 'Refresh export status' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Please retry.');
    expect(screen.getByRole('status')).toHaveTextContent('Export is queued.');
    expect(screen.queryByRole('link', { name: 'Download export' })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Refresh export status' }));
    expect(await screen.findByRole('link', { name: 'Download export' })).toHaveAttribute('href', `http://localhost:3000/api/exports/${token}/download`);
    expect(apiMock.post).not.toHaveBeenCalled();
});

it('retries a rejected cancellation and confirms cancelled state before withholding download', async () => {
    apiMock.post.mockRejectedValueOnce(new Error('Cancellation unavailable.')).mockResolvedValueOnce({ data: { data: { status: 'cancelled' } } });
    apiMock.get.mockResolvedValue({ data: { data: { token, status: 'cancelled', download_url: null } } });
    render(<ExportArtifactStatus artifact={queued} />);
    await userEvent.click(screen.getByRole('button', { name: 'Cancel export' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(apiMock.get).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('button', { name: 'Cancel export' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Export cancelled.'));
    expect(apiMock.post).toHaveBeenLastCalledWith(`/exports/${token}/cancel`);
    expect(screen.queryByRole('button', { name: 'Cancel export' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Download export' })).not.toBeInTheDocument();
});

it.each([
    { ...queued, status: 'ready', expires_at: '2000-01-01T00:00:00Z' },
    { ...queued, status: 'ready', expires_at: '2099-01-01T00:00:00Z', download_url: `https://outside.example/api/exports/${token}/download` },
])('withholds expired or nonlocal export downloads', artifact => {
    render(<ExportArtifactStatus artifact={artifact} />);
    expect(screen.queryByRole('link', { name: 'Download export' })).not.toBeInTheDocument();
    expect(apiMock.get).not.toHaveBeenCalled();
    expect(apiMock.post).not.toHaveBeenCalled();
});

it('refuses a malformed export token without making any request', async () => {
    render(<ExportArtifactStatus artifact={{ token: '../orders', status: 'queued' }} />);
    expect(screen.getByRole('button', { name: 'Refresh export status' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancel export' })).toBeDisabled();
    expect(apiMock.get).not.toHaveBeenCalled();
    expect(apiMock.post).not.toHaveBeenCalled();
});
