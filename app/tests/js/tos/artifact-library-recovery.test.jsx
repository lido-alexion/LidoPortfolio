import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { expect, it } from 'vitest';
import ArtifactLibraryPage from '../../../resources/js/src/pages/ArtifactLibraryPage.jsx';
import ArtifactLibraryDetailPage from '../../../resources/js/src/pages/ArtifactLibraryDetailPage.jsx';
import { apiMock } from './helpers/mockApi.js';

it('does not label an unavailable library as empty and retries the read explicitly', async () => {
    apiMock.get.mockRejectedValueOnce({ response: { status: 503, data: { message: 'Synthetic Library unavailable.' } } }).mockResolvedValueOnce({ data: { data: [] } });
    render(<MemoryRouter><ArtifactLibraryPage /></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic Library unavailable.'); expect(screen.queryByText('No accessible artifacts match these filters.')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry Library', exact: true })); expect(await screen.findByText('No accessible artifacts match these filters.')).toBeVisible();
});
it('retries an unavailable artifact without enabling any mutation before a confirmed read', async () => {
    apiMock.get.mockRejectedValueOnce({ response: { status: 503, data: { message: 'Synthetic artifact unavailable.' } } }).mockResolvedValueOnce({ data: { data: { name: 'Confirmed artifact', slug: 'confirmed', type: 'screener', permission: 'shared', origin: 'user', versions: [] } } });
    render(<MemoryRouter initialEntries={['/artifact-library/synthetic-uuid']}><Routes><Route path="/artifact-library/:uuid" element={<ArtifactLibraryDetailPage />} /></Routes></MemoryRouter>);
    expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic artifact unavailable.'); expect(apiMock.post).not.toHaveBeenCalled(); fireEvent.click(screen.getByRole('button', { name: 'Retry artifact', exact: true })); expect(await screen.findByRole('heading', { name: 'Confirmed artifact' })).toBeVisible(); expect(apiMock.post).not.toHaveBeenCalled();
});
it('associates the prefilled Draft envelope with its exact field label', async () => {
    apiMock.get.mockResolvedValue({ data: { data: [] } });
    render(<MemoryRouter><ArtifactLibraryPage /></MemoryRouter>);
    fireEvent.click(await screen.findByRole('button', { name: 'Create Draft', exact: true }));
    expect(screen.getByLabelText('Artifact envelope JSON', { exact: true })).toHaveValue(expect.stringContaining('schema_version'));
    expect(apiMock.post).not.toHaveBeenCalled();
});
