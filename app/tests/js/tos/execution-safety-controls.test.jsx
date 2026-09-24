import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ExecutionSafetyControls from '../../../resources/js/src/components/ExecutionSafetyControls.jsx';
import { apiEnvelope, axiosOk } from './fixtures/tosApi.js';
import { apiMock } from './helpers/mockApi.js';

vi.mock('../../../resources/js/src/toast.js', () => ({ showToast: vi.fn() }));

afterEach(() => vi.restoreAllMocks());

beforeEach(() => {
    apiMock.get.mockResolvedValue(axiosOk(apiEnvelope({
        execution_state: 'emergency_halt',
        is_halted: true,
        execution_code_label: 'StoX execution code — Google Authenticator',
    })));
    apiMock.post.mockResolvedValue(axiosOk(apiEnvelope({ execution_state: 'normal' })));
});

describe('Execution safety recovery proof selection', () => {
    it('sends a rotating StoX execution code only in the TOTP field', async () => {
        const user = userEvent.setup();
        vi.spyOn(window, 'prompt').mockReturnValue('test-current-code');
        render(<ExecutionSafetyControls user={{ is_admin: false }} />);

        await user.click(await screen.findByRole('button', {
            name: 'Recover with StoX execution code — Google Authenticator',
        }));

        expect(window.prompt).toHaveBeenCalledWith('Enter StoX execution code — Google Authenticator.');
        expect(apiMock.post).toHaveBeenCalledWith('/v1/execution/recover', {
            confirm: true,
            totp: 'test-current-code',
        });
        expect(apiMock.post.mock.calls[0][1]).not.toHaveProperty('recovery_code');
    });

    it('sends a one-time StoX recovery code only in the recovery-code field', async () => {
        const user = userEvent.setup();
        vi.spyOn(window, 'prompt').mockReturnValue('one-time-backup-code');
        render(<ExecutionSafetyControls user={{ is_admin: false }} />);

        await user.click(await screen.findByRole('button', { name: 'Recover with StoX recovery code' }));

        expect(window.prompt).toHaveBeenCalledWith('Enter StoX recovery code.');
        expect(apiMock.post).toHaveBeenCalledWith('/v1/execution/recover', {
            confirm: true,
            recovery_code: 'one-time-backup-code',
        });
        expect(apiMock.post.mock.calls[0][1]).not.toHaveProperty('totp');
    });
});
