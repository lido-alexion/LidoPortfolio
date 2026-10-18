import React, { StrictMode } from 'react';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AssistantDrawer from '../../../resources/js/src/components/AssistantDrawer';
import { streamAssistant } from '../../../resources/js/src/utils/assistantStream';

vi.mock('../../../resources/js/src/utils/assistantStream', () => ({
    streamAssistant: vi.fn(),
    safeSourceUrl: (url) => url?.startsWith('/docs/') ? url : null,
}));
vi.mock('../../../resources/js/src/api', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

const TEMPORARY_UNAVAILABLE = 'The assistant is temporarily unavailable. You can still use How do I? help.';
const originalShowModal = HTMLDialogElement.prototype.showModal;
const originalClose = HTMLDialogElement.prototype.close;

function RouteMarker() {
    const location = useLocation();
    return <output aria-label="Current application route">{location.pathname}{location.search}</output>;
}

function Harness() {
    return (
        <MemoryRouter initialEntries={['/screeners']}>
            <AssistantDrawer />
            <RouteMarker />
            <Routes>
                <Route path="/documentation" element={<main><h1>Deterministic documentation destination</h1></main>} />
            </Routes>
        </MemoryRouter>
    );
}

async function openUnavailableAssistant() {
    streamAssistant.mockRejectedValueOnce(new Error('private transport detail'));
    render(<StrictMode><Harness /></StrictMode>);
    fireEvent.click(screen.getByRole('button', { name: 'Open StoX assistant' }));
    const drawer = screen.getByRole('dialog', { name: 'StoX assistant' });
    fireEvent.change(screen.getByRole('textbox', { name: 'Ask about StoX' }), {
        target: { value: 'How do I recover when assistant help is unavailable?' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Ask', exact: true }));
    await screen.findByText(TEMPORARY_UNAVAILABLE, { exact: true });
    expect(within(drawer).getByRole('navigation', { name: 'How do I? deterministic help' })).toBeInTheDocument();
    expect(screen.getByText('Insufficient documentation', { exact: true })).toBeInTheDocument();
    expect(screen.queryByText('private transport detail')).not.toBeInTheDocument();
    return drawer;
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

it('keeps Browse documentation available after an unavailable answer and follows its application link', async () => {
    const drawer = await openUnavailableAssistant();
    fireEvent.click(within(drawer).getByRole('link', { name: 'Browse documentation' }));

    expect(await screen.findByRole('heading', { name: 'Deterministic documentation destination' })).toBeInTheDocument();
    expect(screen.getByLabelText('Current application route')).toHaveTextContent('/documentation?q=help');
    expect(screen.queryByRole('dialog', { name: 'StoX assistant' })).not.toBeInTheDocument();
});

it('returns focus to Ask StoX when Close assistant is used from the unavailable state', async () => {
    const drawer = await openUnavailableAssistant();
    fireEvent.click(within(drawer).getByRole('button', { name: 'Close assistant' }));

    await waitFor(() => expect(drawer).not.toBeVisible());
    expect(screen.getByRole('button', { name: 'Open StoX assistant' })).toHaveFocus();
});

it('returns focus to Ask StoX when Escape closes the unavailable-state dialog', async () => {
    const drawer = await openUnavailableAssistant();

    await act(async () => {
        fireEvent.cancel(drawer);
    });

    await waitFor(() => expect(drawer).not.toBeVisible());
    expect(screen.getByRole('button', { name: 'Open StoX assistant' })).toHaveFocus();
});
