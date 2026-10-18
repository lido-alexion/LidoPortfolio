import React, { StrictMode } from 'react';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import AssistantDrawer from '../../../resources/js/src/components/AssistantDrawer';
import { streamAssistant } from '../../../resources/js/src/utils/assistantStream';

vi.mock('../../../resources/js/src/utils/assistantStream', () => ({
    streamAssistant: vi.fn(),
    safeSourceUrl: (url) => url?.startsWith('/docs/') ? url : null,
}));
vi.mock('../../../resources/js/src/api', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

const originalShowModal = HTMLDialogElement.prototype.showModal;
const originalClose = HTMLDialogElement.prototype.close;

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

function emitCompleted(call, text, requestId, sourceId) {
    call.onEvent('message.delta', { text });
    call.onEvent('message.completed', {
        grounding: 'grounded',
        request_id: requestId,
        provenance: [{
            source_id: sourceId,
            title: `${sourceId} source`,
            section: 'Reviewed section',
            snippet: `${text} support`,
            url: `/docs/${sourceId}.html`,
        }],
    });
}

beforeEach(() => {
    vi.resetAllMocks();
    HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
    HTMLDialogElement.prototype.close = function close() { this.open = false; };
});

afterEach(() => {
    cleanup();
    HTMLDialogElement.prototype.showModal = originalShowModal;
    HTMLDialogElement.prototype.close = originalClose;
});

it('clears a pending documentation answer, aborts its signal, and ignores its late callbacks and finally', async () => {
    const calls = [];
    streamAssistant.mockImplementation((input, signal, onEvent) => {
        const result = deferred();
        calls.push({ input, signal, onEvent, ...result });
        return result.promise;
    });

    render(<StrictMode><MemoryRouter><AssistantDrawer /></MemoryRouter></StrictMode>);
    fireEvent.click(screen.getByRole('button', { name: 'Open StoX assistant' }));
    const drawer = screen.getByRole('dialog', { name: 'StoX assistant' });
    const question = screen.getByRole('textbox', { name: 'Ask about StoX' });

    fireEvent.change(question, { target: { value: 'First pending documentation question' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ask', exact: true }));
    expect(calls).toHaveLength(1);
    expect(calls[0].input.question).toBe('First pending documentation question');
    expect(calls[0].input.conversation).toEqual([]);
    expect(screen.getByText('Checking documentation', { exact: true })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Clear conversation' }));
    expect(calls[0].signal.aborted).toBe(true);
    expect(screen.getByText('Conversation cleared.', { exact: true })).toBeInTheDocument();
    expect(drawer.querySelectorAll('article')).toHaveLength(0);

    fireEvent.change(question, { target: { value: 'Current question after clear' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ask', exact: true }));
    expect(calls).toHaveLength(2);
    expect(calls[1].input.question).toBe('Current question after clear');
    expect(calls[1].input.conversation).toEqual([]);
    expect(drawer.querySelector('[aria-live="polite"]')).toHaveAttribute('aria-busy', 'true');

    await act(async () => {
        emitCompleted(calls[0], 'Stale answer must stay cleared', 'stale-request', 'stale');
        calls[0].resolve();
        await calls[0].promise;
    });

    expect(screen.queryByText('First pending documentation question')).not.toBeInTheDocument();
    expect(screen.queryByText('Stale answer must stay cleared')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'stale source — Reviewed section' })).not.toBeInTheDocument();
    expect(screen.queryByText('The assistant is temporarily unavailable. You can still use How do I? help.')).not.toBeInTheDocument();
    expect(screen.getByText('Current question after clear')).toBeInTheDocument();
    expect(screen.getByText('Checking documentation', { exact: true })).toBeInTheDocument();
    expect(drawer.querySelector('[aria-live="polite"]')).toHaveAttribute('aria-busy', 'true');
    expect(screen.getByRole('button', { name: 'Ask', exact: true })).toBeDisabled();

    await act(async () => {
        emitCompleted(calls[1], 'Current answer remains authoritative', 'current-request', 'current');
        calls[1].resolve();
        await calls[1].promise;
    });

    expect(screen.getByText('Current answer remains authoritative')).toBeInTheDocument();
    expect(screen.getByText('Grounded', { exact: true })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'current source — Reviewed section' })).toHaveAttribute('href', '/docs/current.html');

    await act(async () => {
        calls[0].onEvent('error', {
            code: 'temporary_failure',
            request_id: 'stale-error',
            provenance: [{ source_id: 'late-error', title: 'Late error source', section: 'Old section', snippet: 'Old evidence', url: '/docs/late-error.html' }],
        });
    });

    expect(screen.getByText('Current answer remains authoritative')).toBeInTheDocument();
    expect(screen.getByText('Grounded', { exact: true })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'current source — Reviewed section' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Late error source — Old section' })).not.toBeInTheDocument();
    expect(screen.queryByText('The assistant is temporarily unavailable. You can still use How do I? help.')).not.toBeInTheDocument();
    expect(drawer.querySelector('[aria-live="polite"]')).toHaveAttribute('aria-busy', 'false');
});
