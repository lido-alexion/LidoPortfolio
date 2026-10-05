import React from 'react';
import { describe, expect, it } from 'vitest';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { fireEvent, render, screen } from '@testing-library/react';
import DocumentationPage from '../../../resources/js/src/pages/DocumentationPage.jsx';
import { apiMock } from './helpers/mockApi.js';

describe('selected deterministic journey help', () => {
    it('renders source-derived steps, match explanation, alternatives, guide, safe navigation and feedback', () => {
        apiMock.post.mockResolvedValue({ data: { data: { recorded: true } } });
        render(<MemoryRouter initialEntries={[{ pathname: '/documentation', search: '?journey=SCR-01', state: { helpQuery: 'create screener' } }]}>
            <Routes><Route path="/documentation" element={<DocumentationPage />} /></Routes>
        </MemoryRouter>);

        expect(screen.getByRole('heading', { name: 'How do I create a screener from scratch?' })).toBeInTheDocument();
        expect(screen.getByRole('list', { name: 'Task steps' })).toContainElement(screen.getByText(/Start creation of a new screener/));
        expect(screen.getByText(/Why this matched: Matched/i)).toBeInTheDocument();
        expect(screen.getByRole('navigation', { name: 'Alternative help topics' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open Screeners' })).toHaveAttribute('href', '/screeners');
        expect(screen.getByRole('link', { name: 'View full guide' })).toHaveAttribute('href', '/docs/journeys/01-screeners.html#scr-01-create-a-screener-from-scratch');
        fireEvent.click(screen.getByRole('button', { name: 'Helpful' }));
        expect(apiMock.post).toHaveBeenCalledWith('/help-feedback', { topic_id: 'SCR-01', event: 'helpful' }, { skipErrorToast: true });
    });

    it('renders only source-marked material prerequisites and warnings inline', () => {
        apiMock.post.mockResolvedValue({ data: { data: { recorded: true } } });
        render(<MemoryRouter initialEntries={['/documentation?journey=EXE-03']}>
            <Routes><Route path="/documentation" element={<DocumentationPage />} /></Routes>
        </MemoryRouter>);
        expect(screen.getByText(/A valid Kite connection for the current session/)).toBeInTheDocument();
        expect(screen.getByText(/StoX requires explicit execution authorization/)).toBeInTheDocument();
    });
});
