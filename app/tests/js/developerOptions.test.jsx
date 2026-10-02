import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import DeveloperOptions from '../../resources/js/src/components/DeveloperOptions.jsx';
import { apiMock } from './tos/helpers/mockApi.js';

function openOptions() {
    localStorage.setItem('devOptions', 'true');
    render(<DeveloperOptions />);
    const hotspot = screen.getByRole('button', { name: 'Open developer options' });
    hotspot.focus();
    fireEvent.click(hotspot);
}

describe('temporary Developer options', () => {
    it.each([null, '', 'false', 'TRUE', 'True', '1', ' true '])('renders no entry point for %j', (value) => {
        if (value !== null) localStorage.setItem('devOptions', value);
        render(<DeveloperOptions />);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(apiMock.post).not.toHaveBeenCalled();
    });

    it('renders the hotspot only for the exact key and opens the labelled dialog', () => {
        openOptions();
        expect(screen.getByRole('button', { name: 'Open developer options' })).toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'Developer options' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reset guided tour' })).toBeInTheDocument();
    });

    it('does not enable itself or accept a differently cased key', () => {
        localStorage.setItem('DevOptions', 'true');
        render(<DeveloperOptions />);
        expect(localStorage.getItem('devOptions')).toBeNull();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('hides the hotspot and open modal when the flag is removed in another tab', () => {
        openOptions();
        localStorage.removeItem('devOptions');
        fireEvent(window, new StorageEvent('storage', { key: 'devOptions', newValue: null }));
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(document.body.style.overflow).toBe('');
    });

    it('fails closed when browser storage is unavailable', () => {
        const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Storage denied'); });
        try {
            render(<DeveloperOptions />);
            expect(screen.queryByRole('button')).not.toBeInTheDocument();
        } finally {
            spy.mockRestore();
        }
    });

    it('posts without a target identity, disables the pending action, and shows success', async () => {
        let resolve;
        apiMock.post.mockReturnValueOnce(new Promise((done) => { resolve = done; }));
        openOptions();
        fireEvent.click(screen.getByRole('button', { name: 'Reset guided tour' }));
        expect(apiMock.post).toHaveBeenCalledExactlyOnceWith('/developer-options/guided-tour/reset');
        expect(screen.getByRole('button', { name: 'Resetting…' })).toBeDisabled();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        await act(async () => resolve({ data: { data: { show_welcome_prompt: true } } }));
        expect(screen.getByRole('status')).toHaveTextContent('Guided tour reset. Reload the page');
        expect(screen.getByRole('button', { name: 'Reset guided tour' })).toBeEnabled();
    });

    it.each([
        [{ response: { data: { message: 'Guided tour is not available for this account.' } } }, 'Guided tour is not available for this account.'],
        [new Error('Network error'), 'Could not reset guided tour. Please try again.'],
    ])('shows inline failure and allows retry', async (failure, message) => {
        apiMock.post.mockRejectedValueOnce(failure).mockResolvedValueOnce({ data: {} });
        openOptions();
        fireEvent.click(screen.getByRole('button', { name: 'Reset guided tour' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(message);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Reset guided tour' }));
        expect(await screen.findByRole('status')).toHaveTextContent('Guided tour reset.');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('closes on Escape and returns focus to the hotspot', () => {
        openOptions();
        expect(screen.getByRole('button', { name: 'Close developer options' })).toHaveFocus();
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Open developer options' })).toHaveFocus();
    });
});
