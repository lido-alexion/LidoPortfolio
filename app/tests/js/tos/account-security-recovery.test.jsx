import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import api from '../../../resources/js/src/api';
import PersonalApiTokensPanel from '../../../resources/js/src/components/PersonalApiTokensPanel';
import TotpSettingsPanel from '../../../resources/js/src/components/TotpSettingsPanel';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() }, getApiErrorMessage: () => 'Synthetic account unavailable' }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
beforeEach(() => { cleanup(); vi.clearAllMocks(); });
const unavailable = () => ({ response: { status: 503 }, message: 'Synthetic unavailable' });

it('blocks execution-code setup while its current state is unknown and recovers the enabled state', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { data: { enabled: true, authenticator_app_name: 'Reviewed app' } } });
    render(<TotpSettingsPanel />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Execution-code status could not be loaded');
    expect(screen.queryByRole('button', { name: 'Set up StoX execution code' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry execution-code status' }));
    expect(await screen.findByRole('button', { name: 'Disable', exact: true })).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});
it('retains newly confirmed one-time recovery codes if the subsequent status read fails', async () => {
    let reads = 0; api.get.mockImplementation(async () => { reads++; if (reads >= 3) throw unavailable(); return { data: { data: { enabled: false, pending: reads === 2 } } }; });
    api.post.mockImplementation(async path => ({ data: { data: path.endsWith('/begin') ? { qr_svg: '<svg aria-label="Synthetic enrollment"></svg>' } : { recovery_codes: ['SYNTHETIC_NOT_A_REAL_RECOVERY_CODE'] } } }));
    render(<TotpSettingsPanel />); fireEvent.click(await screen.findByRole('button', { name: 'Set up StoX execution code' }));
    fireEvent.change(await screen.findByLabelText('StoX execution code — Authenticator app'), { target: { value: '000000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Execution-code status could not be loaded');
    expect(screen.getByText('SYNTHETIC_NOT_A_REAL_RECOVERY_CODE')).toBeVisible(); expect(api.post).toHaveBeenCalledTimes(2);
});
it('distinguishes an unavailable token list from a confirmed empty list', async () => {
    api.get.mockRejectedValueOnce(unavailable()).mockResolvedValue({ data: { data: [] } });
    render(<PersonalApiTokensPanel />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Personal API tokens could not be loaded');
    expect(screen.queryByText('No personal API tokens.')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Retry personal API tokens' }));
    expect(await screen.findByText('No personal API tokens.')).toBeVisible(); expect(api.post).not.toHaveBeenCalled();
});
it('retains the token and catches a rejected confirmed revoke for deliberate retry', async () => {
    let revoked = false; api.get.mockImplementation(async () => ({ data: { data: revoked ? [] : [{ id: 501, name: 'Read-only fixture', abilities: ['portfolio:read'] }] } }));
    api.delete.mockRejectedValueOnce(unavailable()).mockImplementationOnce(async () => { revoked = true; return { data: {} }; });
    vi.spyOn(window, 'confirm').mockReturnValue(true); render(<PersonalApiTokensPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Revoke token' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Token revocation could not be confirmed');
    expect(screen.getByText('Read-only fixture')).toBeVisible(); fireEvent.click(screen.getByRole('button', { name: 'Revoke token' }));
    expect(await screen.findByText('No personal API tokens.')).toBeVisible(); expect(api.delete).toHaveBeenCalledTimes(2); vi.restoreAllMocks();
});
