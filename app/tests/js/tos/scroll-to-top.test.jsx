import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import ScrollToTop from '../../../resources/js/src/components/ScrollToTop.jsx';

function renderWithScrollContainer(scrollTop = 0) {
    document.body.innerHTML = '';
    const main = document.createElement('main');
    main.className = 'lido-main';
    main.scrollTop = scrollTop;
    main.scrollTo = vi.fn();
    const shell = document.createElement('div');
    shell.className = 'lido-shell';
    shell.appendChild(main);
    document.body.appendChild(shell);
    return main;
}

describe('ScrollToTop', () => {
    it('stays hidden until the main scroll container passes the threshold', () => {
        const main = renderWithScrollContainer(0);
        const { rerender } = render(<ScrollToTop />);
        expect(screen.queryByRole('button', { name: 'Scroll to top of page' })).not.toBeInTheDocument();

        main.scrollTop = 400;
        fireEvent.scroll(main);
        expect(screen.getByRole('button', { name: 'Scroll to top of page' })).toBeInTheDocument();
        rerender(<ScrollToTop />);
    });

    it('scrolls the shell main container and honors reduced motion', () => {
        const main = renderWithScrollContainer(400);
        window.matchMedia = vi.fn().mockReturnValue({ matches: true });
        render(<ScrollToTop />);

        fireEvent.click(screen.getByRole('button', { name: 'Scroll to top of page' }));
        expect(main.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });
});
