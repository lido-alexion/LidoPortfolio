import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import SettingsPage from '../../../resources/js/src/pages/SettingsPage';
vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/context/AuthContext', () => ({ useAuth: () => ({ user: { id: 2, is_admin: false }, logout: vi.fn() }) }));
vi.mock('../../../resources/js/src/context/PortfolioContext', () => ({ usePortfolio: () => ({ activePortfolio: { id: 1 } }) }));
vi.mock('../../../resources/js/src/components/TotpSettingsPanel', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/PersonalApiTokensPanel', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/BrokerConnectionPanel', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/ExecutionModePanel', () => ({ default: () => null }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); });
const unavailable = () => ({ response: { status: 503 }, message: 'Synthetic unavailable' });
const at = path => render(<MemoryRouter initialEntries={[path]}><SettingsPage /></MemoryRouter>);
it('recovers unavailable own-session reads without claiming no sessions', async () => {
    let failed = true; api.get.mockImplementation(async path => { if (path === '/auth/sessions' && failed) throw unavailable(); return { data: { data: path === '/auth/sessions' ? [] : {} } }; });
    at('/settings/account'); expect(await screen.findByRole('alert')).toHaveTextContent('Active sessions could not be loaded'); expect(screen.queryByText('No active sessions found.')).toBeNull(); failed = false;
    fireEvent.click(screen.getByRole('button', { name: 'Retry active sessions' })); expect(await screen.findByText('No active sessions found.')).toBeVisible(); expect(api.delete).not.toHaveBeenCalled();
});
it('catches uncertain non-current session revoke and retains the named session', async () => {
    let revoked = false; api.get.mockImplementation(async path => ({ data: { data: path === '/auth/sessions' ? (revoked ? [] : [{ id: 901, device: 'Synthetic second device', is_current: false }]) : {} } }));
    api.delete.mockRejectedValueOnce(unavailable()).mockImplementationOnce(async () => { revoked = true; return { data: {} }; });
    at('/settings/account'); fireEvent.click(await screen.findByRole('button', { name: 'Revoke', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Session revocation could not be confirmed'); expect(screen.getByText('Synthetic second device')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Revoke', exact: true })); expect(await screen.findByText('No active sessions found.')).toBeVisible(); expect(api.delete).toHaveBeenCalledTimes(2);
});
it('retains failed Portfolio settings drafts and succeeds only after confirmed retry', async () => {
    api.get.mockResolvedValue({ data: { data: { default_stoploss_percent: '8', notification_schedules: [] } } }); api.put.mockRejectedValueOnce(unavailable()).mockResolvedValueOnce({ data: {} });
    at('/settings/portfolio'); const input = await screen.findByLabelText('Portfolio Stop-Loss %'); fireEvent.change(input, { target: { value: '9' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save portfolio settings' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Portfolio settings could not be confirmed'); expect(input).toHaveValue('9');
    fireEvent.click(screen.getByRole('button', { name: 'Save portfolio settings' })); expect(await screen.findByText('Portfolio settings saved')).toBeVisible(); expect(api.put).toHaveBeenCalledTimes(2);
});
