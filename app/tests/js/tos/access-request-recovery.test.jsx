import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import api from '../../../resources/js/src/api';
import { showToast } from '../../../resources/js/src/toast';
import AccessRequestsAdminSection from '../../../resources/js/src/components/AccessRequestsAdminSection';
import UserManagementPage from '../../../resources/js/src/pages/UserManagementPage';
import { MemoryRouter } from 'react-router-dom';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn() }, getApiErrorMessage: error => error.response?.data?.message || 'Synthetic unavailable' }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, is_admin: true } }) }));
const row = (id = 51) => ({ id, email: `request-${id}@example.test`, full_name: `Request ${id}`, status: 'pending', verification_status: 'unverified' });
const unavailable = () => ({ response: { status: 503, data: { message: 'Synthetic unavailable' } } });
beforeEach(() => { cleanup(); vi.restoreAllMocks(); vi.clearAllMocks(); api.post.mockReset(); api.get.mockImplementation(async path => ({ data: path === '/access-requests' ? { data: [row()], pending_count: 1 } : { data: [] } })); });

it('does not report an unavailable initial admission read as no access requests and retries explicitly', async () => {
    let failed = true;
    api.get.mockImplementation(async path => { if (path === '/access-requests' && failed) throw unavailable(); return { data: { data: [], pending_count: 0 } }; });
    render(<AccessRequestsAdminSection />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Access requests could not be loaded');
    expect(screen.queryByText('No access requests in this view.')).not.toBeInTheDocument();
    failed = false; fireEvent.click(screen.getByRole('button', { name: 'Retry access requests' }));
    expect(await screen.findByText('No access requests in this view.')).toBeVisible();
    expect(api.post).not.toHaveBeenCalled();
});

it('retains known requests on a failed filter read and blocks mutation until fresh recovery', async () => {
    api.get.mockImplementation(async (path, options) => { if (path === '/access-requests' && options.params.pending_only === false) throw unavailable(); return { data: path === '/access-requests' ? { data: [row()], pending_count: 1 } : { data: [] } }; });
    render(<AccessRequestsAdminSection />); await screen.findByText(row().email);
    fireEvent.click(screen.getByRole('button', { name: 'Show all statuses' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Access requests could not be loaded');
    expect(screen.getByText(row().email)).toBeVisible();
    expect(screen.getByRole('button', { name: 'Create invite', exact: true })).toBeDisabled();
    expect(api.post).not.toHaveBeenCalled();
});

it('retains the selected request and exposes an exact read-only detail retry', async () => {
    let failed = true;
    api.get.mockImplementation(async path => { if (path === '/access-requests/51') { if (failed) throw unavailable(); return { data: { request: row(), history: [], audit_events: [] } }; } return { data: path === '/access-requests' ? { data: [row()], pending_count: 1 } : { data: [] } }; });
    render(<AccessRequestsAdminSection />); await screen.findByText(row().email); fireEvent.click(screen.getByRole('button', { name: 'Review', exact: true }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Request details could not be loaded');
    failed = false; fireEvent.click(screen.getByRole('button', { name: 'Retry request details' }));
    expect(await screen.findByRole('heading', { name: 'Audit trail' })).toBeVisible();
    expect(api.post).not.toHaveBeenCalled();
});

it('does not replace a newer request review with an earlier delayed response', async () => {
    let resolveFirst;
    api.get.mockImplementation(async path => { if (path === '/access-requests/51') return new Promise(resolve => { resolveFirst = resolve; }); if (path === '/access-requests/52') return { data: { request: row(52), history: [], audit_events: [] } }; return { data: path === '/access-requests' ? { data: [row(), row(52)], pending_count: 2 } : { data: [] } }; });
    render(<AccessRequestsAdminSection />); await screen.findByText(row(52).email);
    fireEvent.click(within(screen.getByText(row().email).closest('tr')).getByRole('button', { name: 'Review' }));
    fireEvent.click(within(screen.getByText(row(52).email).closest('tr')).getByRole('button', { name: 'Review' }));
    await screen.findByRole('heading', { name: `Review — ${row(52).email}` });
    await act(async () => resolveFirst({ data: { request: row(), history: [], audit_events: [] } }));
    await waitFor(() => expect(screen.getByRole('heading', { name: `Review — ${row(52).email}` })).toBeVisible());
    expect(screen.queryByRole('heading', { name: `Review — ${row().email}` })).not.toBeInTheDocument();
});

it.each(['ignore', 'reject'])('retains the private reason after rejected %s and cancels without another write', async action => {
    const prompt = vi.spyOn(window, 'prompt').mockReturnValueOnce('  Private review  ').mockReturnValueOnce(null);
    api.post.mockRejectedValue(unavailable());
    render(<AccessRequestsAdminSection />); await screen.findByText(row().email);
    const name = action === 'ignore' ? 'Ignore' : 'Reject'; fireEvent.click(screen.getByRole('button', { name, exact: true }));
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Synthetic unavailable', 'danger'));
    fireEvent.click(screen.getByRole('button', { name, exact: true }));
    expect(prompt).toHaveBeenNthCalledWith(2, 'Optional internal reason (not shown to the applicant):', 'Private review');
    expect(api.post).toHaveBeenCalledTimes(1); expect(api.post).toHaveBeenCalledWith(`/access-requests/51/${action}`, { reason: 'Private review' });
    expect(screen.getByText(row().email)).toBeVisible();
    prompt.mockRestore();
});

it('does not report an unavailable invitation read as no invites or permit another creation', async () => {
    let failed = true;
    api.get.mockImplementation(async path => { if (path === '/invites' && failed) throw unavailable(); return { data: { data: [] } }; });
    render(<MemoryRouter><UserManagementPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic unavailable');
    expect(screen.queryByText('No invites yet.')).not.toBeInTheDocument();
    const button = within(screen.getByLabelText('Email address').closest('form')).getByRole('button', { name: 'Create invite', exact: true });
    expect(button).toBeDisabled(); failed = false; fireEvent.click(screen.getByRole('button', { name: 'Retry user management' }));
    expect(await screen.findByText('No invites yet.')).toBeVisible(); expect(button).toBeEnabled(); expect(api.post).not.toHaveBeenCalled();
});

it('retains confirmed invitation material when a later shared refresh fails', async () => {
    let failNext = false;
    api.get.mockImplementation(async path => { if (path === '/invites' && failNext) throw unavailable(); return { data: { data: path === '/invites' ? [{ id: 91, email: 'known@example.test', status: 'pending' }] : [] } }; });
    render(<MemoryRouter><UserManagementPage /></MemoryRouter>); await screen.findByText('known@example.test');
    failNext = true;
    fireEvent.click(screen.getByRole('button', { name: 'Refresh user management' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic unavailable');
    expect(screen.getByText('known@example.test')).toBeVisible(); expect(screen.queryByText('No invites yet.')).not.toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();
});
