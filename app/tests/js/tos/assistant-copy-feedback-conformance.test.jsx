import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AssistantDrawer from '../../../resources/js/src/components/AssistantDrawer';
import api from '../../../resources/js/src/api';
import { streamAssistant } from '../../../resources/js/src/utils/assistantStream';

vi.mock('../../../resources/js/src/utils/assistantStream', async () => {
    const actual = await vi.importActual('../../../resources/js/src/utils/assistantStream');
    return { ...actual, streamAssistant: vi.fn() };
});
vi.mock('../../../resources/js/src/api', () => ({ default: { post: vi.fn() } }));

const answer = 'A screener filters stocks using conditions you choose.';
const source = {
    source_id: 'journey-screener',
    title: 'Screeners',
    section: 'Create a screener',
    snippet: 'Choose conditions, then run the screener.',
    url: '/docs/screeners.html#creation',
};
const requestId = 'doc-answer-42';

function completeAnswer() {
    streamAssistant.mockImplementation(async (_input, _signal, emit) => {
        emit('message.delta', { text: answer });
        emit('message.completed', {
            grounding: 'grounded',
            request_id: requestId,
            provenance: [source],
        });
    });
}

function openDrawerAndAsk() {
    render(<MemoryRouter><AssistantDrawer /></MemoryRouter>);
    fireEvent.click(screen.getByRole('button', { name: 'Open StoX assistant' }));
    fireEvent.change(screen.getByLabelText('Ask about StoX'), { target: { value: 'How do I create a screener?' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ask', exact: true }));
}

beforeEach(() => {
    vi.resetAllMocks();
    HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    HTMLDialogElement.prototype.close = function () { this.open = false; };
    Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText: vi.fn().mockResolvedValue(undefined) },
    });
});
afterEach(() => cleanup());

describe('AI-02 answer copy and feedback', () => {
    it('copies the completed answer and its absolute StoX source link through the visible control', async () => {
        completeAnswer();
        openDrawerAndAsk();

        const drawer = await screen.findByRole('dialog', { name: 'StoX assistant' });
        await within(drawer).findByText('Grounded', { exact: true });
        fireEvent.click(within(drawer).getByText('Sources used (1)'));
        expect(within(drawer).getByRole('link', { name: 'Screeners — Create a screener' }))
            .toHaveAttribute('href', source.url);
        expect(within(drawer).getByText(source.snippet)).toBeInTheDocument();

        fireEvent.click(within(drawer).getByRole('button', { name: 'Copy response' }));
        const copied = `${answer}\n\n${source.title}: ${new URL(source.url, window.location.origin)}`;
        await waitFor(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith(copied));
        expect(within(drawer).getByRole('status')).toHaveTextContent('Response copied.');
    });

    it('sends helpful and not-helpful feedback against the answer request ID and keeps retry feedback visible', async () => {
        completeAnswer();
        api.post
            .mockResolvedValueOnce({ data: { saved: true } })
            .mockRejectedValueOnce(new Error('synthetic feedback failure'))
            .mockResolvedValueOnce({ data: { saved: true } });
        openDrawerAndAsk();

        const drawer = await screen.findByRole('dialog', { name: 'StoX assistant' });
        await within(drawer).findByText('Grounded', { exact: true });

        fireEvent.click(within(drawer).getByRole('button', { name: 'Helpful', exact: true }));
        await waitFor(() => expect(within(drawer).getByRole('status')).toHaveTextContent('Feedback saved.'));
        expect(api.post).toHaveBeenNthCalledWith(1, '/ai/assistant/feedback', {
            request_id: requestId,
            helpful: true,
            comment: '',
        });

        fireEvent.click(within(drawer).getByText('Not helpful'));
        fireEvent.change(within(drawer).getByLabelText('Optional comment'), {
            target: { value: 'The steps need a clearer prerequisite.' },
        });
        fireEvent.click(within(drawer).getByRole('button', { name: 'Send feedback' }));
        await waitFor(() => expect(within(drawer).getByRole('status'))
            .toHaveTextContent('Feedback could not be saved. Please try again.'));
        expect(api.post).toHaveBeenNthCalledWith(2, '/ai/assistant/feedback', {
            request_id: requestId,
            helpful: false,
            comment: 'The steps need a clearer prerequisite.',
        });

        fireEvent.click(within(drawer).getByRole('button', { name: 'Send feedback' }));
        await waitFor(() => expect(within(drawer).getByRole('status')).toHaveTextContent('Feedback saved.'));
        expect(api.post).toHaveBeenNthCalledWith(3, '/ai/assistant/feedback', {
            request_id: requestId,
            helpful: false,
            comment: 'The steps need a clearer prerequisite.',
        });
        expect(api.post).toHaveBeenCalledTimes(3);
    });
});
