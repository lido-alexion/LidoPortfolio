import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ProfileMenu from '../../../resources/js/src/components/ProfileMenu';

const { logoutMock } = vi.hoisted(() => ({ logoutMock: vi.fn() }));
vi.mock('../../../resources/js/src/context/AuthContext', () => ({ useAuth: () => ({ logout: logoutMock }) }));
vi.mock('../../../resources/js/src/components/ThemeToggle', () => ({ default: () => null }));

beforeEach(() => {
    cleanup();
    vi.clearAllMocks();
    logoutMock.mockReset();
});

it('keeps the current page, reports uncertainty, and permits one deliberate retry', async () => {
    let rejectFirst;
    const firstLogout = new Promise((_resolve, reject) => { rejectFirst = reject; });
    logoutMock.mockReturnValueOnce(firstLogout).mockRejectedValueOnce(new Error('private server detail'));
    render(<MemoryRouter><ProfileMenu user={{ name: 'Synthetic Investor', email: 'investor@example.test' }} /></MemoryRouter>);

    fireEvent.click(screen.getByRole('button', { name: 'Profile menu for Synthetic Investor' }));
    const logoutButton = screen.getByRole('button', { name: /Logout$/ });
    await act(async () => {
        fireEvent.click(logoutButton);
        fireEvent.click(logoutButton);
    });
    expect(logoutMock).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: /Logging out…$/ })).toBeDisabled();

    await act(async () => rejectFirst(new Error('private server detail')));
    expect(await screen.findByRole('alert')).toHaveTextContent('Logout could not be confirmed. Your session may still be active. Please retry.');
    expect(screen.queryByText('private server detail')).toBeNull();
    expect(screen.getByRole('button', { name: /Retry logout$/ })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Profile menu for Synthetic Investor' })).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: /Retry logout$/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Logout could not be confirmed. Your session may still be active. Please retry.');
    expect(logoutMock).toHaveBeenCalledTimes(2);
});
