import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import AssistantDrawer from '../../../resources/js/src/components/AssistantDrawer';
import { streamAssistant } from '../../../resources/js/src/utils/assistantStream';
vi.mock('../../../resources/js/src/utils/assistantStream', () => ({ streamAssistant: vi.fn(), safeSourceUrl: url => url?.startsWith('/docs/') ? url : null }));
vi.mock('../../../resources/js/src/api', () => ({ default: { post: vi.fn().mockResolvedValue({}) } }));
beforeEach(() => {
    vi.clearAllMocks();
    HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    HTMLDialogElement.prototype.close = function () { this.open = false; };
});
it('keeps follow-up memory in session, exposes sources and clears memory', async () => {
    streamAssistant.mockImplementation(async (input, signal, event) => {
        event('message.delta', { text: 'Open Screeners to create a screener.' });
        event('message.completed', { grounding: 'grounded', request_id: 'r1', provenance: [{ source_id: 'scr', title: 'Screeners', section: 'Create', snippet: 'Open Screeners.', url: '/docs/screeners.html' }] });
    });
    render(<MemoryRouter><AssistantDrawer /></MemoryRouter>);
    fireEvent.click(screen.getByRole('button', { name: 'Open StoX assistant' }));
    fireEvent.change(screen.getByLabelText('Ask about StoX'), { target: { value: 'Create a screener' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ask', exact: true }));
    await screen.findByText('Grounded');
    expect(screen.getByText('Sources used (1)')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Ask about StoX'), { target: { value: 'What next?' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ask', exact: true }));
    await waitFor(() => expect(streamAssistant).toHaveBeenCalledTimes(2));
    expect(streamAssistant.mock.calls[1][0].conversation[0].question).toBe('Create a screener');
    fireEvent.click(screen.getByText('Clear conversation'));
    expect(screen.queryByText('What next?')).not.toBeInTheDocument();
    expect(screen.getByText('Conversation cleared.')).toBeInTheDocument();
});
it('preserves deterministic help when runtime fails', async () => {
    streamAssistant.mockRejectedValue(new Error('private error'));
    render(<MemoryRouter><AssistantDrawer /></MemoryRouter>);
    fireEvent.click(screen.getByRole('button', { name: 'Open StoX assistant' }));
    fireEvent.change(screen.getByLabelText('Ask about StoX'), { target: { value: 'Create screener' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ask', exact: true }));
    await screen.findByText('Insufficient documentation');
    expect(screen.getByRole('navigation', { name: 'How do I? deterministic help' })).toBeInTheDocument();
    expect(screen.queryByText('private error')).not.toBeInTheDocument();
});
