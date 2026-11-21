import React from 'react';
import { MemoryRouter } from 'react-router-dom';
import { render } from '@testing-library/react';
import { KiteConnectionProvider } from '../../../../resources/js/src/context/KiteConnectionContext.jsx';
import { TEST_USER } from '../fixtures/tosApi.js';

export function renderWithKiteConnection(ui) {
    return render(
        <MemoryRouter initialEntries={['/dashboard']}>
            <KiteConnectionProvider user={TEST_USER}>{ui}</KiteConnectionProvider>
        </MemoryRouter>,
    );
}
