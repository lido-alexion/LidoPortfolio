import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import AuthContext from '../../../resources/js/src/context/AuthContext.jsx';
import AcceptInvitePage from '../../../resources/js/src/pages/AcceptInvitePage.jsx';
import RequestAccountPage from '../../../resources/js/src/pages/RequestAccountPage.jsx';
import { apiMock } from './helpers/mockApi.js';

function openInvite(refreshUser = vi.fn().mockResolvedValue({ id: 1 })) {
    return render(<AuthContext.Provider value={{ refreshUser }}><MemoryRouter initialEntries={['/invite/synthetic-invite-token']}><Routes>
        <Route path="/invite/:token" element={<AcceptInvitePage />} />
        <Route path="/" element={<p>Returned to account</p>} />
    </Routes></MemoryRouter></AuthContext.Provider>);
}
const validLink = () => apiMock.get.mockResolvedValue({ data: { data: { email: 'investor@example.test' } } });

describe('invitation and access-request conformance', () => {
    it('associates all invitation labels with their fields', async () => {
        validLink(); openInvite();
        expect(await screen.findByLabelText('Email')).toHaveValue('investor@example.test');
        expect(screen.getByLabelText('Your name')).toHaveAttribute('autocomplete', 'name');
        expect(screen.getByLabelText('Password')).toHaveAttribute('autocomplete', 'new-password');
        expect(screen.getByLabelText('Confirm password')).toHaveAttribute('autocomplete', 'new-password');
    });
    it('keeps rejected invitation acceptance editable and retries without refreshing a session early', async () => {
        validLink();
        apiMock.post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { password: ['Confirmation does not match.'] } } } }).mockResolvedValueOnce({ data: {} });
        const refreshUser = vi.fn().mockResolvedValue({ id: 1 });
        const view = openInvite(refreshUser);
        await screen.findByRole('button', { name: 'Create account & sign in' });
        const inputs = view.container.querySelectorAll('input[type="password"]');
        fireEvent.change(inputs[0], { target: { value: 'synthetic-password-1' } });
        fireEvent.change(inputs[1], { target: { value: 'synthetic-password-2' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create account & sign in' }));
        await screen.findByText('Confirmation does not match.');
        expect(screen.getByRole('button', { name: 'Create account & sign in' })).toBeEnabled();
        expect(inputs[0]).toHaveValue('synthetic-password-1');
        expect(refreshUser).not.toHaveBeenCalled();
        fireEvent.change(inputs[1], { target: { value: 'synthetic-password-1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create account & sign in' }));
        await screen.findByText('Returned to account');
        expect(refreshUser).toHaveBeenCalledTimes(1);
    });
    it('refuses expired invitations without account creation or session refresh', async () => {
        apiMock.get.mockRejectedValue({ response: { status: 410, data: { message: 'Invitation expired.' } } });
        const refreshUser = vi.fn(); openInvite(refreshUser);
        expect(await screen.findByRole('alert')).toHaveTextContent('Invitation expired.');
        expect(screen.queryByRole('button', { name: 'Create account & sign in' })).not.toBeInTheDocument();
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(refreshUser).not.toHaveBeenCalled();
    });
    it('describes email verification as advisory for Admin review while preserving invite-only access', async () => {
        apiMock.get.mockResolvedValue({ data: { data: { turnstile_site_key: '' } } });
        render(<MemoryRouter><RequestAccountPage /></MemoryRouter>);
        await waitFor(() => expect(apiMock.get).toHaveBeenCalled());
        expect(screen.getByText(/StoX is invite-only/)).toHaveTextContent('Email verification helps establish ownership; an administrator can review your request before verification.');
        expect(screen.getByRole('button', { name: 'Submit access request' })).toBeEnabled();
    });
});
