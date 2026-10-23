import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { AuthProvider, useAuth } from '../../../resources/js/src/context/AuthContext';
import api from '../../../resources/js/src/api';
import { resetCsrfCookie } from '../../../resources/js/src/auth/csrf';
import { clearAllDashboardCaches } from '../../../resources/js/src/utils/dashboardCache';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../resources/js/src/auth/csrf', () => ({ ensureCsrfCookie: vi.fn().mockResolvedValue(undefined), resetCsrfCookie: vi.fn() }));
vi.mock('../../../resources/js/src/utils/dashboardCache', () => ({ clearAllDashboardCaches: vi.fn() }));

function AuthProbe() {
    const { user, logout } = useAuth();
    const [result, setResult] = React.useState('');
    return (
        <div>
            <span data-testid="identity">{user?.email || 'signed out'}</span>
            <span data-testid="result">{result}</span>
            <button type="button" onClick={async () => {
                try {
                    await logout();
                    setResult('resolved');
                } catch {
                    setResult('rejected');
                }
            }}>Invoke logout</button>
        </div>
    );
}

function renderProvider() {
    return render(<AuthProvider><AuthProbe /></AuthProvider>);
}

beforeEach(() => {
    cleanup();
    vi.clearAllMocks();
    localStorage.clear();
    api.get.mockResolvedValue({ data: { user: { id: 7, email: 'investor@example.test' } } });
    api.post.mockResolvedValue({ status: 204 });
});

it('clears local auth and user caches only after confirmed logout', async () => {
    renderProvider();
    expect(await screen.findByText('investor@example.test')).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Invoke logout' }));

    expect(await screen.findByText('resolved', { exact: true })).toBeVisible();
    expect(screen.getByTestId('identity')).toHaveTextContent('signed out');
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenCalledWith('/auth/logout', {}, { skipErrorToast: true });
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(resetCsrfCookie).toHaveBeenCalledTimes(1);
    expect(clearAllDashboardCaches).toHaveBeenCalledTimes(1);
});

it('treats a definitive 401 as already unauthenticated and clears local auth', async () => {
    api.post.mockRejectedValueOnce({ response: { status: 401 } });
    renderProvider();
    expect(await screen.findByText('investor@example.test')).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Invoke logout' }));

    expect(await screen.findByText('resolved', { exact: true })).toBeVisible();
    expect(screen.getByTestId('identity')).toHaveTextContent('signed out');
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(resetCsrfCookie).toHaveBeenCalledTimes(1);
    expect(clearAllDashboardCaches).toHaveBeenCalledTimes(1);
});

it.each([
    ['503', { response: { status: 503 } }],
    ['403', { response: { status: 403 } }],
    ['unresolved 419', { response: { status: 419 } }],
    ['network failure', new Error('synthetic network failure')],
])('preserves auth state and propagates %s without an identity probe', async (_label, error) => {
    api.post.mockRejectedValueOnce(error);
    renderProvider();
    expect(await screen.findByText('investor@example.test')).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Invoke logout' }));

    expect(await screen.findByText('rejected', { exact: true })).toBeVisible();
    expect(screen.getByTestId('identity')).toHaveTextContent('investor@example.test');
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.get).toHaveBeenCalledTimes(1);
    expect(resetCsrfCookie).not.toHaveBeenCalled();
    expect(clearAllDashboardCaches).not.toHaveBeenCalled();
});

it('keeps auth state until a pending logout is confirmed', async () => {
    let finishLogout;
    api.post.mockImplementationOnce(() => new Promise(resolve => { finishLogout = resolve; }));
    renderProvider();
    expect(await screen.findByText('investor@example.test')).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Invoke logout' }));
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('identity')).toHaveTextContent('investor@example.test');
    expect(resetCsrfCookie).not.toHaveBeenCalled();
    expect(clearAllDashboardCaches).not.toHaveBeenCalled();
    expect(screen.queryByText('resolved', { exact: true })).toBeNull();

    await act(async () => finishLogout({ status: 204 }));
    expect(await screen.findByText('resolved', { exact: true })).toBeVisible();
    expect(screen.getByTestId('identity')).toHaveTextContent('signed out');
    expect(resetCsrfCookie).toHaveBeenCalledTimes(1);
    expect(clearAllDashboardCaches).toHaveBeenCalledTimes(1);
});
