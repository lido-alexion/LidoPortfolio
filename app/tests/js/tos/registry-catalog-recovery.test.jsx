import React from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import ScreenerRegistryPage from '../../../resources/js/src/pages/ScreenerRegistryPage.jsx';
import ScreenerRegistryDetailPage from '../../../resources/js/src/pages/ScreenerRegistryDetailPage.jsx';
import StrategyRegistryDetailPage from '../../../resources/js/src/pages/StrategyRegistryDetailPage.jsx';
vi.mock('../../../resources/js/src/api', async original => ({ ...(await original()), default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
const fail = () => Promise.reject({ response: { status: 503, data: { message: 'Synthetic registry unavailable.' } } });
const envelope = type => ({ schema_version: '1.0', artifact_type: type, artifact_id: 69, artifact_version: 2, name: `Reviewed ${type}`, slug: `reviewed-${type}`, definition_hash: 'synthetic-reviewed-hash', metadata: { ownership: 'own', status: 'draft', origin: 'user' }, definition: {} });
beforeEach(() => vi.resetAllMocks());
it('does not report an unavailable screener catalogue as no matching screeners and retries', async () => {
 let unavailable = true; api.get.mockImplementation(path => path.endsWith('/meta') ? Promise.resolve({ data: { data: {} } }) : unavailable ? fail() : Promise.resolve({ data: { data: [envelope('screener')] } })); render(<MemoryRouter><ScreenerRegistryPage /></MemoryRouter>);
 await screen.findByText('Screener Registry could not be loaded.'); expect(screen.queryByText('No screeners match the filters.')).toBeNull(); unavailable = false; fireEvent.click(screen.getByRole('button', { name: 'Retry Screener Registry' })); await screen.findByText('Reviewed screener'); expect(api.post).not.toHaveBeenCalled();
});
for (const [type, Component, route] of [['screener', ScreenerRegistryDetailPage, '/screeners/registry/:id'], ['strategy', StrategyRegistryDetailPage, '/strategy/registry/:id']]) {
 it(`retries an unavailable ${type} envelope without losing its intended identity`, async () => {
  let unavailable = true; api.get.mockImplementation(path => path.endsWith('/versions') ? Promise.resolve({ data: { data: [] } }) : unavailable ? fail() : Promise.resolve({ data: { data: envelope(type) } })); render(<MemoryRouter initialEntries={[route.replace(':id', '69')]}><Routes><Route path={route} element={<Component />} /></Routes></MemoryRouter>);
  await screen.findByText('Synthetic registry unavailable.'); unavailable = false; fireEvent.click(screen.getByRole('button', { name: 'Retry registry artifact' })); await screen.findByRole('heading', { name: `Reviewed ${type}` }); expect(api.post).not.toHaveBeenCalled();
 });
 it(`retains a known ${type} envelope and distinguishes an unavailable version history from zero versions`, async () => {
  let unavailable = true; api.get.mockImplementation(path => path.endsWith('/versions') ? unavailable ? fail() : Promise.resolve({ data: { data: [{ version: 2, definition_hash: 'synthetic-reviewed-hash', change_notes: 'Reviewed immutable version', status: 'draft' }] } }) : Promise.resolve({ data: { data: envelope(type) } })); render(<MemoryRouter initialEntries={[route.replace(':id', '69')]}><Routes><Route path={route} element={<Component />} /></Routes></MemoryRouter>);
  await screen.findByText('Registry version history could not be loaded.'); expect(screen.getByRole('heading', { name: `Reviewed ${type}` })).toBeInTheDocument(); expect(screen.queryByText('No versions recorded yet.')).toBeNull(); unavailable = false; fireEvent.click(screen.getByRole('button', { name: 'Retry registry versions' })); await screen.findByText('Reviewed immutable version'); expect(api.post).not.toHaveBeenCalled();
 });
}
