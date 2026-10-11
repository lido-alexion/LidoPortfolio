import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import AuthContext from '../../../resources/js/src/context/AuthContext.jsx';
import ResetPasswordPage from '../../../resources/js/src/pages/ResetPasswordPage.jsx';
import { apiMock } from './helpers/mockApi.js';

function openReset(refreshUser = vi.fn().mockResolvedValue({ id: 1 })) {
    return render(<AuthContext.Provider value={{ refreshUser }}>
        <MemoryRouter initialEntries={['/reset-password/dedicated-test-token']}>
            <Routes>
                <Route path="/reset-password/:token" element={<ResetPasswordPage />} />
                <Route path="/" element={<p>Returned to account</p>} />
            </Routes>
        </MemoryRouter>
    </AuthContext.Provider>);
}
function validLink() {
    apiMock.get.mockResolvedValue({ data: { data: { name: 'Test investor', email: 'investor@example.test' } } });
}

describe('password reset recovery', () => {
    it('labels account and credential fields for assistive technology', async () => {
        validLink();
        openReset();
        expect(await screen.findByLabelText('Account')).toHaveValue('Test investor (investor@example.test)');
        expect(screen.getByLabelText('New password')).toHaveAttribute('autocomplete', 'new-password');
        expect(screen.getByLabelText('Confirm new password')).toHaveAttribute('autocomplete', 'new-password');
    });

    it('retains the form after a rejected submission and lets the investor correct and retry', async () => {
        validLink();
        apiMock.post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { password: ['The password confirmation does not match.'] } } } })
            .mockResolvedValueOnce({ data: {} });
        const refreshUser = vi.fn().mockResolvedValue({ id: 1 });
        openReset(refreshUser);
        const password = await screen.findByLabelText('New password');
        const confirmation = screen.getByLabelText('Confirm new password');
        fireEvent.change(password, { target: { value: 'synthetic-password-1' } });
        fireEvent.change(confirmation, { target: { value: 'synthetic-password-2' } });
        fireEvent.click(screen.getByRole('button', { name: 'Update password & sign in' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('The password confirmation does not match.');
        expect(password).toHaveValue('synthetic-password-1');
        expect(screen.getByRole('button', { name: 'Update password & sign in' })).toBeEnabled();
        expect(refreshUser).not.toHaveBeenCalled();
        fireEvent.change(confirmation, { target: { value: 'synthetic-password-1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Update password & sign in' }));
        await screen.findByText('Returned to account');
        expect(apiMock.post).toHaveBeenLastCalledWith('/reset-password/accept', {
            token: 'dedicated-test-token', password: 'synthetic-password-1', password_confirmation: 'synthetic-password-1', remember: true,
        });
        expect(refreshUser).toHaveBeenCalledTimes(1);
    });

    it('keeps expired links closed without submitting or refreshing a session', async () => {
        apiMock.get.mockRejectedValue({ response: { status: 410, data: { message: 'This reset link has expired.' } } });
        const refreshUser = vi.fn();
        openReset(refreshUser);
        expect(await screen.findByRole('alert')).toHaveTextContent('This reset link has expired.');
        expect(screen.getByRole('link', { name: 'Back to sign in' })).toHaveAttribute('href', '/');
        expect(screen.queryByRole('button', { name: 'Update password & sign in' })).not.toBeInTheDocument();
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(refreshUser).not.toHaveBeenCalled();
    });

    it('disables repeated submission while acceptance is pending', async () => {
        validLink();
        let finish;
        apiMock.post.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
        openReset();
        fireEvent.change(await screen.findByLabelText('New password'), { target: { value: 'synthetic-password' } });
        fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: 'synthetic-password' } });
        fireEvent.click(screen.getByRole('button', { name: 'Update password & sign in' }));
        await waitFor(() => expect(apiMock.post).toHaveBeenCalledTimes(1));
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        finish({ data: {} });
        await screen.findByText('Returned to account');
    });
});
