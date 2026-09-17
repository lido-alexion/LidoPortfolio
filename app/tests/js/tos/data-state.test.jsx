import React from 'react';
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import DataState from '../../../resources/js/src/components/DataState.jsx';

describe('DataState', () => {
    it('renders loading as a polite status', () => {
        render(<DataState variant="loading" message="Loading candidates." />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading candidates.');
    });

    it('renders empty content and an optional action', () => {
        render(<DataState variant="empty" title="No reports" action={<button type="button">Generate</button>} />);
        expect(screen.getByRole('status')).toHaveTextContent('No reports');
        expect(screen.getByRole('button', { name: 'Generate' })).toBeInTheDocument();
    });

    it('renders an announced error state', () => {
        render(<DataState variant="error" message="Reports unavailable." />);
        expect(screen.getByRole('alert')).toHaveTextContent('Reports unavailable.');
    });
});
