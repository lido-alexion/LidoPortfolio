import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));
vi.mock('../../../resources/js/src/api', () => ({ default: apiMock }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));

import AlertPoliciesPage from '../../../resources/js/src/pages/AlertPoliciesPage.jsx';

const meta = {
    stock_universes: [{ value: 'holdings', label: 'Holdings' }],
    condition_operators: [{ value: 'lt', label: 'Less than' }],
    compare_types: [{ value: 'constant', label: 'Constant' }],
    action_types: [{ value: 'track', label: 'Track' }],
    columns: [{ key: 'latest_close', label: 'Latest close' }],
};

const policy = (id, name) => ({
    id,
    name,
    is_system: false,
    is_enabled: true,
    alert_definition: `Definition for ${name}`,
    stock_universe: 'holdings',
    condition_column: 'latest_close',
    condition_operator: 'lt',
    compare_type: 'constant',
    compare_constant: 100,
    message_template: '{{symbol}} review',
    action_type: 'track',
});

const metaResponse = (data = meta) => ({ data: { data } });
const listResponse = (data) => ({ data: { data } });
const unavailable = () => Promise.reject({ response: { status: 503, data: { message: 'Synthetic private server detail' } } });
const renderPage = () => render(<MemoryRouter><AlertPoliciesPage /></MemoryRouter>);
const refreshSamePortfolio = async () => {
    await act(async () => {
        window.dispatchEvent(new CustomEvent('portfolio-changed', { detail: { portfolioId: 1 } }));
    });
};
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
};

beforeEach(() => {
    vi.resetAllMocks();
    sessionStorage.clear();
    sessionStorage.setItem('portfolio_active_id', '1');
});

describe('alert policy list read recovery', () => {
    it('distinguishes an initial list failure from confirmed empty and retries the existing GET pair', async () => {
        let listAttempts = 0;
        apiMock.get.mockImplementation((path) => {
            if (path.endsWith('/meta')) return Promise.resolve(metaResponse());
            listAttempts++;
            return listAttempts === 1 ? unavailable() : Promise.resolve(listResponse([]));
        });

        renderPage();
        expect(await screen.findByRole('alert')).toHaveTextContent('Alert policies could not be loaded.');
        expect(screen.getByRole('alert')).not.toHaveTextContent('Synthetic private server detail');
        expect(screen.queryByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Retry policies' }));
        expect(await screen.findByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeInTheDocument();
        expect(apiMock.get.mock.calls.map(([path]) => path)).toEqual([
            '/alert-policies/meta', '/alert-policies', '/alert-policies/meta', '/alert-policies',
        ]);
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(apiMock.put).not.toHaveBeenCalled();
        expect(apiMock.delete).not.toHaveBeenCalled();
    });

    it('accepts the existing minimal metadata shape with optional stock universes omitted', async () => {
        const existingMeta = {
            columns: [{ key: 'latest_close', label: 'Latest close' }],
            condition_operators: [{ value: 'lt', label: 'Less than' }],
            compare_types: [{ value: 'constant', label: 'Constant' }],
            action_types: [{ value: 'track', label: 'Track' }],
        };
        apiMock.get.mockImplementation((path) => path.endsWith('/meta')
            ? Promise.resolve(metaResponse(existingMeta))
            : Promise.resolve(listResponse([])));

        renderPage();
        expect(await screen.findByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeInTheDocument();
        expect(screen.queryByRole('alert')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'New policy' }));
        expect(screen.getByLabelText('Column')).toBeInTheDocument();
    });

    it('treats a failed metadata read as unavailable, then accepts only valid metadata with the list', async () => {
        let failMeta = true;
        apiMock.get.mockImplementation((path) => {
            if (path.endsWith('/meta')) return failMeta ? unavailable() : Promise.resolve(metaResponse());
            return Promise.resolve(listResponse([]));
        });

        renderPage();
        expect(await screen.findByRole('alert')).toHaveTextContent('Alert policies could not be loaded.');
        expect(screen.queryByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeNull();
        failMeta = false;
        fireEvent.click(screen.getByRole('button', { name: 'Retry policies' }));
        expect(await screen.findByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeInTheDocument();
        expect(apiMock.get).toHaveBeenCalledTimes(4);
    });

    it('keeps a confirmed empty list unconfirmed after a later read failure', async () => {
        let failList = false;
        apiMock.get.mockImplementation((path) => {
            if (path.endsWith('/meta')) return Promise.resolve(metaResponse());
            return failList ? unavailable() : Promise.resolve(listResponse([]));
        });

        renderPage();
        expect(await screen.findByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeInTheDocument();
        failList = true;
        await refreshSamePortfolio();
        expect(await screen.findByRole('alert')).toHaveTextContent('Alert policies could not be loaded.');
        expect(screen.queryByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeNull();
    });

    it('does not treat a malformed null list response as confirmed empty', async () => {
        let malformed = true;
        apiMock.get.mockImplementation((path) => {
            if (path.endsWith('/meta')) return Promise.resolve(metaResponse());
            return malformed ? Promise.resolve(listResponse(null)) : Promise.resolve(listResponse([]));
        });

        renderPage();
        expect(await screen.findByRole('alert')).toHaveTextContent('Alert policies could not be loaded.');
        expect(screen.queryByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeNull();
        malformed = false;
        fireEvent.click(screen.getByRole('button', { name: 'Retry policies' }));
        expect(await screen.findByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeInTheDocument();
    });

    it('retains same-portfolio rows and an open draft on failure, then replaces them after explicit retry', async () => {
        let listAttempts = 0;
        apiMock.get.mockImplementation((path) => {
            if (path.endsWith('/meta')) return Promise.resolve(metaResponse());
            listAttempts++;
            if (listAttempts === 1) return Promise.resolve(listResponse([policy(73, 'Original policy')]));
            if (listAttempts === 2) return unavailable();
            return Promise.resolve(listResponse([policy(74, 'Updated policy')]));
        });

        renderPage();
        expect(await screen.findByText('Original policy')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Edit', exact: true }));
        fireEvent.change(screen.getByLabelText('Alert name'), { target: { value: 'Unsaved draft' } });
        await refreshSamePortfolio();

        expect(await screen.findByRole('alert')).toHaveTextContent('Showing the last successfully loaded policies');
        expect(screen.getByText('Original policy')).toBeInTheDocument();
        expect(screen.getByLabelText('Alert name')).toHaveValue('Unsaved draft');
        expect(screen.queryByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Retry policies' }));
        expect(await screen.findByText('Updated policy')).toBeInTheDocument();
        expect(screen.queryByText('Original policy')).toBeNull();
        expect(screen.getByLabelText('Alert name')).toHaveValue('Unsaved draft');
        expect(apiMock.get).toHaveBeenCalledTimes(6);
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(apiMock.put).not.toHaveBeenCalled();
        expect(apiMock.delete).not.toHaveBeenCalled();
    });

    it('clears prior-scope presentation and ignores late old-scope completion', async () => {
        const lateAReject = deferred();
        const lateAResolve = deferred();
        const currentB = deferred();
        let listAttempt = 0;
        apiMock.get.mockImplementation((path) => {
            if (path.endsWith('/meta')) return Promise.resolve(metaResponse());
            listAttempt++;
            if (listAttempt === 1) return Promise.resolve(listResponse([policy(1, 'Portfolio A policy')]));
            if (listAttempt === 2) return lateAReject.promise;
            if (listAttempt === 3) return lateAResolve.promise;
            if (listAttempt === 4) return currentB.promise;
            throw new Error('Unexpected list request');
        });

        renderPage();
        expect(await screen.findByText('Portfolio A policy')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Edit', exact: true }));
        await refreshSamePortfolio();
        await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(4));
        await refreshSamePortfolio();
        await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(6));

        sessionStorage.setItem('portfolio_active_id', '2');
        await act(async () => {
            window.dispatchEvent(new CustomEvent('portfolio-changed', { detail: { portfolioId: 2 } }));
        });
        expect(screen.queryByText('Portfolio A policy')).toBeNull();
        expect(screen.queryByLabelText('Alert name')).toBeNull();

        await act(async () => currentB.resolve(listResponse([policy(2, 'Portfolio B policy')])));
        expect(await screen.findByText('Portfolio B policy')).toBeInTheDocument();
        await act(async () => lateAResolve.resolve(listResponse([policy(3, 'Late old-scope policy')])));
        await act(async () => lateAReject.reject({ response: { status: 503, data: { message: 'Late old-scope failure' } } }));
        expect(screen.getByText('Portfolio B policy')).toBeInTheDocument();
        expect(screen.queryByText('Portfolio A policy')).toBeNull();
        expect(screen.queryByText('Late old-scope policy')).toBeNull();
        expect(screen.queryByRole('alert')).toBeNull();
        expect(apiMock.post).not.toHaveBeenCalled();
        expect(apiMock.put).not.toHaveBeenCalled();
        expect(apiMock.delete).not.toHaveBeenCalled();
    });

    it.each(['resolve', 'reject'])('does not auto-request the new scope after an old-scope read %s without an event', async (outcome) => {
        const lateOldScope = deferred();
        let listAttempt = 0;
        apiMock.get.mockImplementation((path) => {
            if (path.endsWith('/meta')) return Promise.resolve(metaResponse());
            listAttempt++;
            if (listAttempt === 1) return Promise.resolve(listResponse([policy(1, 'Old portfolio policy')]));
            if (listAttempt === 2) return lateOldScope.promise;
            return Promise.resolve(listResponse([policy(2, 'New portfolio policy')]));
        });

        renderPage();
        expect(await screen.findByText('Old portfolio policy')).toBeInTheDocument();
        await refreshSamePortfolio();
        await waitFor(() => expect(apiMock.get).toHaveBeenCalledTimes(4));
        sessionStorage.setItem('portfolio_active_id', '2');

        if (outcome === 'resolve') {
            await act(async () => lateOldScope.resolve(listResponse([policy(3, 'Late old-scope policy')])));
        } else {
            await act(async () => lateOldScope.reject({ response: { status: 503, data: { message: 'Late old-scope failure' } } }));
        }

        expect(await screen.findByRole('alert')).toHaveTextContent('Alert policies could not be loaded.');
        expect(screen.queryByText('Old portfolio policy')).toBeNull();
        expect(screen.queryByText('Late old-scope policy')).toBeNull();
        expect(apiMock.get).toHaveBeenCalledTimes(4);

        fireEvent.click(screen.getByRole('button', { name: 'Retry policies' }));
        expect(await screen.findByText('New portfolio policy')).toBeInTheDocument();
        expect(apiMock.get).toHaveBeenCalledTimes(6);
    });

    it('does not replay a successful create when its follow-up read fails and is retried', async () => {
        let listAttempts = 0;
        apiMock.get.mockImplementation((path) => {
            if (path.endsWith('/meta')) return Promise.resolve(metaResponse());
            listAttempts++;
            if (listAttempts === 1) return Promise.resolve(listResponse([]));
            if (listAttempts === 2) return unavailable();
            return Promise.resolve(listResponse([policy(73, 'Created policy')]));
        });
        apiMock.post.mockResolvedValue({ data: { data: policy(73, 'Created policy') } });

        renderPage();
        expect(await screen.findByText('No alert policies yet. Create one to generate alerts from holdings.')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'New policy' }));
        fireEvent.change(screen.getByLabelText('Alert name'), { target: { value: 'Created policy' } });
        fireEvent.change(screen.getByLabelText('Column'), { target: { value: 'latest_close' } });
        fireEvent.change(screen.getByLabelText('Compare value type'), { target: { value: 'constant' } });
        fireEvent.change(screen.getByLabelText('Constant value'), { target: { value: '100' } });
        fireEvent.change(screen.getByLabelText('Alert message'), { target: { value: '{{symbol}} review' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Create policy' }).closest('form'));

        expect(await screen.findByRole('alert')).toHaveTextContent('Alert policies could not be loaded.');
        expect(apiMock.post).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Retry policies' }));
        expect(await screen.findByText('Created policy')).toBeInTheDocument();
        expect(apiMock.post).toHaveBeenCalledTimes(1);
        expect(apiMock.get).toHaveBeenCalledTimes(6);
    });
});
