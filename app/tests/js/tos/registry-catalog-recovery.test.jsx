import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import ScreenerRegistryPage from '../../../resources/js/src/pages/ScreenerRegistryPage.jsx';
import ScreenerRegistryDetailPage from '../../../resources/js/src/pages/ScreenerRegistryDetailPage.jsx';
import StrategyRegistryPage from '../../../resources/js/src/pages/StrategyRegistryPage.jsx';
import StrategyRegistryDetailPage from '../../../resources/js/src/pages/StrategyRegistryDetailPage.jsx';
vi.mock('../../../resources/js/src/api', async original => ({ ...(await original()), default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
const fail = () => Promise.reject({ response: { status: 503, data: { message: 'Synthetic registry unavailable.' } } });
const envelope = type => ({ schema_version: '1.0', artifact_type: type, artifact_id: 69, artifact_version: 2, name: `Reviewed ${type}`, slug: `reviewed-${type}`, definition_hash: 'synthetic-reviewed-hash', metadata: { ownership: 'own', status: 'draft', origin: 'user' }, definition: {} });
beforeEach(() => vi.resetAllMocks());
afterEach(() => vi.restoreAllMocks());
it('does not report an unavailable screener catalogue as no matching screeners and retries', async () => {
 let unavailable = true; api.get.mockImplementation(path => path.endsWith('/meta') ? Promise.resolve({ data: { data: {} } }) : unavailable ? fail() : Promise.resolve({ data: { data: [envelope('screener')] } })); render(<MemoryRouter><ScreenerRegistryPage /></MemoryRouter>);
 await screen.findByText('Screener Registry could not be loaded.'); expect(screen.queryByText('No screeners match the filters.')).toBeNull(); unavailable = false; fireEvent.click(screen.getByRole('button', { name: 'Retry Screener Registry' })); await screen.findByText('Reviewed screener'); expect(api.post).not.toHaveBeenCalled();
});
it('retains known strategies and does not report an unavailable catalogue as empty', async () => {
 let unavailable = false; api.get.mockImplementation(path => path.endsWith('/meta') ? Promise.resolve({ data: { data: {} } }) : unavailable ? fail() : Promise.resolve({ data: { data: [envelope('strategy')] } })); render(<MemoryRouter><StrategyRegistryPage /></MemoryRouter>);
 await screen.findByText('Reviewed strategy'); unavailable = true; fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'reviewed' } }); await screen.findByText('Strategy Registry could not be loaded.'); expect(screen.getByText('Reviewed strategy')).toBeInTheDocument(); expect(screen.queryByText('No strategies match the filters.')).toBeNull(); unavailable = false; fireEvent.click(screen.getByRole('button', { name: 'Retry Strategy Registry' })); await screen.findByText('Reviewed strategy'); expect(api.post).not.toHaveBeenCalled();
});

it('allows pausing the last enabled strategy and shows exact archive blockers with existing resolution links', async () => {
 let lifecycle = 'active';
 const row = () => ({ ...envelope('strategy'), metadata: { status: lifecycle === 'active' ? 'active' : 'draft', lifecycle_status: lifecycle, is_enabled: lifecycle === 'active' } });
 api.get.mockImplementation(path => path.endsWith('/meta')
  ? Promise.resolve({ data: { data: { counts: { total: 1, active: lifecycle === 'active' ? 1 : 0, disabled: lifecycle === 'disabled' ? 1 : 0, draft: 0, archived: 0 }, statuses: [], origins: [] } } })
  : Promise.resolve({ data: { data: [row()] } }));
 api.post.mockImplementation(path => {
  if (path.endsWith('/disable')) { lifecycle = 'disabled'; return Promise.resolve({ data: { data: row() } }); }
  return Promise.reject({ response: { status: 409, data: { error: { message: 'This strategy still has outstanding records to resolve.' }, meta: { blockers: [{ code: 'owned_holdings', label: 'Open holdings owned by this strategy', count: 2, href: '/holdings' }] } } } });
 });
 vi.spyOn(window, 'confirm').mockReturnValue(true);
 vi.spyOn(window, 'prompt').mockReturnValue('No longer needed');
 render(<MemoryRouter><StrategyRegistryPage /></MemoryRouter>);
 await screen.findByText('Reviewed strategy');
 fireEvent.click(screen.getByRole('button', { name: 'Disable' }));
 await screen.findByText('disabled');
 expect(api.post).toHaveBeenCalledWith('/v1/strategy-registry/69/disable');
 fireEvent.click(screen.getByRole('button', { name: 'Retire' }));
 await screen.findByText('Open holdings owned by this strategy (2)');
 expect(screen.getByRole('link', { name: 'Open resolution view' })).toHaveAttribute('href', '/holdings');
});

it('restores an archived strategy as disabled and offers a separate explicit Enable action', async () => {
 let lifecycle = 'archived';
 const archived = () => ({ ...envelope('strategy'), metadata: { status: 'archived', lifecycle_status: lifecycle, archive_reason: 'Retired by user' } });
 api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/versions') ? [] : archived() } }));
 api.post.mockImplementation(async path => { if (path.endsWith('/restore')) lifecycle = 'disabled'; return { data: { data: archived() } }; });
 render(<MemoryRouter initialEntries={['/strategy/registry/69']}><Routes><Route path="/strategy/registry/:id" element={<StrategyRegistryDetailPage />} /></Routes></MemoryRouter>);
 await screen.findByRole('heading', { name: 'Reviewed strategy' });
 fireEvent.click(screen.getByRole('button', { name: 'Restore as disabled' }));
 await screen.findByRole('button', { name: 'Enable' });
 expect(api.post).toHaveBeenCalledWith('/v1/strategy-registry/69/restore');
 expect(api.post).not.toHaveBeenCalledWith('/v1/strategy-registry/69/activate');
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

for (const [type, Component, route] of [['screener', ScreenerRegistryDetailPage, '/screeners/registry/:id'], ['strategy', StrategyRegistryDetailPage, '/strategy/registry/:id']]) {
 it(`clears the previous Portfolio's ${type} envelope and retries the same identity in the new scope`, async () => {
  let otherScope = false; api.get.mockImplementation(path => path.endsWith('/versions') ? Promise.resolve({ data: { data: [] } }) : otherScope ? fail() : Promise.resolve({ data: { data: envelope(type) } })); render(<MemoryRouter initialEntries={[route.replace(':id', '69')]}><Routes><Route path={route} element={<Component />} /></Routes></MemoryRouter>);
  await screen.findByRole('heading', { name: `Reviewed ${type}` }); otherScope = true; fireEvent(window, new CustomEvent('portfolio-changed', { detail: { portfolioId: 2 } })); await screen.findByText('Synthetic registry unavailable.'); expect(screen.queryByRole('heading', { name: `Reviewed ${type}` })).toBeNull(); expect(screen.getByRole('button', { name: 'Export JSON', exact: true })).toBeDisabled(); expect(api.post).not.toHaveBeenCalled();
 });
}
for (const [type, Component] of [['screener', ScreenerRegistryPage], ['strategy', StrategyRegistryPage]]) {
 it(`clears the previous Portfolio's ${type} list instead of relabelling owned rows after a switch`, async () => {
  let otherScope = false; api.get.mockImplementation(path => path.endsWith('/meta') ? Promise.resolve({ data: { data: {} } }) : otherScope ? fail() : Promise.resolve({ data: { data: [envelope(type)] } })); render(<MemoryRouter><Component /></MemoryRouter>);
  await screen.findByText(`Reviewed ${type}`); otherScope = true; fireEvent(window, new CustomEvent('portfolio-changed', { detail: { portfolioId: 2 } })); await screen.findByRole('button', { name: `Retry ${type === 'screener' ? 'Screener' : 'Strategy'} Registry` }); expect(screen.queryByText(`Reviewed ${type}`)).toBeNull(); expect(api.post).not.toHaveBeenCalled();
 });
}

for (const [type, Component, route] of [['screener', ScreenerRegistryDetailPage, '/screeners/registry/:id'], ['strategy', StrategyRegistryDetailPage, '/strategy/registry/:id']]) {
 it(`allows keyboard access to the immutable ${type} version evidence without writes`, async () => {
  const versions = [{ version: 2, definition_hash: 'synthetic-reviewed-hash', change_notes: 'Reviewed keyboard evidence', status: 'draft' }];
  const original = JSON.stringify(versions);
  api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('/versions') ? versions : envelope(type) } }));
  render(<MemoryRouter initialEntries={[route.replace(':id', '69')]}><Routes><Route path={route} element={<Component />} /></Routes></MemoryRouter>);
  await screen.findByText('Reviewed keyboard evidence');
  const region = screen.getByText('Reviewed keyboard evidence').closest('.table-responsive');
  region.focus();
  expect(region).toHaveFocus();
  fireEvent.keyDown(region, { key: 'ArrowRight' });
  expect(region).toHaveFocus();
  expect(JSON.stringify(versions)).toBe(original);
  expect(api.post).not.toHaveBeenCalled();
 });
}
