import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import api from '../../../resources/js/src/api';
import { showToast } from '../../../resources/js/src/toast';
import DashboardLayoutToolbar from '../../../resources/js/src/components/DashboardLayoutToolbar.jsx';
import StockDetailsComboChart from '../../../resources/js/src/components/charts/StockDetailsComboChart.jsx';
import { factoryDashboardLayout } from '../../../resources/js/src/utils/dashboardLayouts.js';
vi.mock('../../../resources/js/src/api', async importOriginal => ({ ...(await importOriginal()), default: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/ExportDataButton', () => ({ default: () => null }));
vi.mock('../../../resources/js/src/components/ExportBasketPanel', () => ({ default: () => null }));
const rejected = () => Promise.reject({ response: { status: 503, data: { message: 'Synthetic service unavailable.' } } });
const record = () => ({ id: 91, name: 'Reviewed layout', is_default: false, definition: factoryDashboardLayout() });
const prices = [{ price_date: '2026-01-01', close_price: 10, volume: 100 }, { price_date: '2026-10-01', close_price: 20, volume: 120 }];
const points = ['2026-01-01', '2026-04-01', '2026-07-01', '2026-10-01'].map(as_of => ({ as_of, value: 3 }));
const deferred = () => { let resolve; let reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
beforeEach(() => { vi.clearAllMocks(); localStorage.clear(); vi.spyOn(window, 'prompt').mockReturnValue('Imported reviewed layout'); });
describe('dashboard and combo chart recovery', () => {
 it('reports an unconfirmed import separately from invalid JSON and permits the same file again', async () => {
  api.get.mockResolvedValue({ data: { data: [] } }); api.post.mockImplementationOnce(rejected).mockResolvedValue({ data: { data: record() } }); render(<DashboardLayoutToolbar userId={5} />);
  const file = { size: 400, text: async () => JSON.stringify(factoryDashboardLayout()) }; const input = document.querySelector('input[type=file]'); fireEvent.change(input, { target: { files: [file] } });
  await waitFor(() => expect(showToast).toHaveBeenCalledWith('Dashboard import could not be confirmed. Your file is unchanged; retry the import.', 'danger')); expect(showToast).not.toHaveBeenCalledWith('Dashboard JSON is invalid or exceeds the 32 KB limit.', 'danger');
  fireEvent.change(input, { target: { files: [file] } }); await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2)); expect(api.post.mock.calls[1]).toEqual(api.post.mock.calls[0]);
 });
 it('does not present a fallback as the saved default when account preference is unavailable', async () => {
  let unavailable = true; api.get.mockImplementation(path => path.endsWith('combo-chart-preference') ? unavailable ? rejected() : Promise.resolve({ data: { data: { default_preset_id: 'price-pe' } } }) : Promise.resolve({ data: { data: { points } } }));
  render(<StockDetailsComboChart stockId={42} prices={prices} />); await screen.findByText('Saved combo chart preference could not be loaded.'); expect(screen.queryByRole('button', { name: 'Default chart' })).toBeNull();
  unavailable = false; fireEvent.click(screen.getByRole('button', { name: 'Retry combo chart preference' })); await waitFor(() => expect(screen.getByLabelText('Combo chart preset')).toHaveValue('price-pe')); expect(screen.getByRole('button', { name: 'Default chart' })).toBeDisabled(); expect(api.put).not.toHaveBeenCalled();
 });
 it('recovers unavailable history with GETs and preserves canonical observation points', async () => {
  let unavailable = true; api.get.mockImplementation(path => path.endsWith('combo-chart-preference') ? Promise.resolve({ data: { data: { default_preset_id: 'price-pe' } } }) : path.includes('/pe/') && unavailable ? rejected() : Promise.resolve({ data: { data: { points } } }));
  render(<StockDetailsComboChart stockId={42} prices={prices} />); await screen.findByText(/Some combo chart history is temporarily unavailable/); expect(screen.getByLabelText('Combo chart preset')).toHaveValue('price-volume'); const original = JSON.stringify(points);
  unavailable = false; fireEvent.click(screen.getByRole('button', { name: 'Retry combo chart history' })); await waitFor(() => expect(screen.getByLabelText('Combo chart preset')).toHaveValue('price-pe')); expect(JSON.stringify(points)).toBe(original); expect(api.get).toHaveBeenCalledTimes(13); expect(api.put).not.toHaveBeenCalled();
 });
 it('renders real Price + Volume observations while unrelated metric histories are still pending', async () => {
  const requests = new Map();
  api.get.mockImplementation(path => {
   if (path.endsWith('combo-chart-preference')) return Promise.resolve({ data: { data: { default_preset_id: null } } });
   const metric = path.split('/').at(-2); const request = deferred(); requests.set(metric, request); return request.promise;
  });
  render(<StockDetailsComboChart stockId={42} prices={prices} />);
  const chart = await screen.findByRole('region', { name: 'Stock Details combo chart' });
  expect(screen.getByLabelText('Combo chart preset')).toHaveValue('price-volume');
  expect(screen.getByRole('checkbox', { name: 'Show Price' })).toBeChecked();
  expect(screen.getByRole('checkbox', { name: 'Show Volume' })).toBeChecked();
  expect(screen.queryByText('No observations for this range and frequency.')).toBeNull();
  const peOption = screen.getByLabelText('Combo chart preset').querySelector('option[value="price-pe"]');
  expect(peOption.disabled).toBe(true);
  expect(peOption.textContent).toContain('Loading historical P/E data');
  expect(api.get).toHaveBeenCalledTimes(7);
  expect(requests.size).toBe(6);
  expect(api.put).not.toHaveBeenCalled();
 });
 it('keeps an available account default pending without mislabeling fallback, then selects it when its own history resolves', async () => {
  const pe = deferred();
  api.get.mockImplementation(path => {
   if (path.endsWith('combo-chart-preference')) return Promise.resolve({ data: { data: { default_preset_id: 'price-pe' } } });
   if (path.includes('/pe/history')) return pe.promise;
   return Promise.resolve({ data: { data: { points } } });
  });
  render(<StockDetailsComboChart stockId={42} prices={prices} />);
  const chart = await screen.findByRole('region', { name: 'Stock Details combo chart' });
  const selector = screen.getByLabelText('Combo chart preset');
  expect(selector).toHaveValue('price-volume');
  expect(screen.getByText(/Loading the saved default’s history/)).toBeVisible();
  expect(screen.getByRole('button', { name: 'Loading saved default…' })).toBeDisabled();
  await act(async () => pe.resolve({ data: { data: { points } } }));
  await waitFor(() => expect(selector).toHaveValue('price-pe'));
  expect(screen.queryByText(/Loading the saved default’s history/)).toBeNull();
  expect(screen.getByRole('button', { name: 'Default chart' })).toBeDisabled();
  expect(screen.queryByText('No observations for this range and frequency.')).toBeNull();
  expect(api.put).not.toHaveBeenCalled();
 });
 it('keeps an intentional user selection when a slow saved-default history completes', async () => {
  const pe = deferred();
  api.get.mockImplementation(path => {
   if (path.endsWith('combo-chart-preference')) return Promise.resolve({ data: { data: { default_preset_id: 'price-pe' } } });
   if (path.includes('/pe/history')) return pe.promise;
   return Promise.resolve({ data: { data: { points } } });
  });
  render(<StockDetailsComboChart stockId={42} prices={prices} />);
  const selector = await screen.findByLabelText('Combo chart preset');
  fireEvent.change(selector, { target: { value: 'price-pb' } });
  await waitFor(() => expect(selector).toHaveValue('price-pb'));
  await act(async () => pe.resolve({ data: { data: { points } } }));
  await waitFor(() => expect(screen.getByRole('option', { name: 'Price + P/E', exact: true })).toBeEnabled());
  expect(selector).toHaveValue('price-pb');
  expect(api.put).not.toHaveBeenCalled();
 });
 it('keeps Price + Volume available when a metric history rejects', async () => {
  api.get.mockImplementation(path => path.endsWith('combo-chart-preference') ? Promise.resolve({ data: { data: { default_preset_id: null } } }) : path.includes('/pe/history') ? rejected() : Promise.resolve({ data: { data: { points } } }));
  render(<StockDetailsComboChart stockId={42} prices={prices} />);
  const chart = await screen.findByRole('region', { name: 'Stock Details combo chart' });
  expect(screen.getByLabelText('Combo chart preset')).toHaveValue('price-volume');
  const peOption = screen.getByLabelText('Combo chart preset').querySelector('option[value="price-pe"]');
  expect(peOption.disabled).toBe(true);
  expect(peOption.textContent).toContain('Temporarily unavailable');
  expect(screen.getByText(/Some combo chart history is temporarily unavailable/)).toBeVisible();
  expect(screen.queryByText('No observations for this range and frequency.')).toBeNull();
  expect(api.put).not.toHaveBeenCalled();
 });
 it('retains the selected chart and shows the canonical error after a rejected account default write', async () => {
  api.get.mockImplementation(path => Promise.resolve({ data: { data: path.endsWith('combo-chart-preference') ? { default_preset_id: 'price-volume' } : { points } } })); api.put.mockImplementation(rejected);
  await act(async () => { render(<StockDetailsComboChart stockId={42} prices={prices} />); }); await waitFor(() => { expect(screen.getByRole('button', { name: 'Default chart' })).toBeDisabled(); expect(screen.getByRole('option', { name: 'Price + P/E', exact: true })).toBeEnabled(); }); const preset = screen.getByLabelText('Combo chart preset'); fireEvent.change(preset, { target: { value: 'price-pe' } }); await waitFor(() => expect(preset).toHaveValue('price-pe')); fireEvent.click(await screen.findByRole('button', { name: 'Set as default' })); await screen.findByText('Synthetic service unavailable.'); expect(api.put).toHaveBeenCalledTimes(1); expect(api.put.mock.calls[0].slice(0, 2)).toEqual(['/v1/stock-details/combo-chart-preference', { default_preset_id: 'price-pe' }]); expect(screen.getByLabelText('Combo chart preset')).toHaveValue('price-pe'); expect(screen.getByRole('button', { name: 'Set as default' })).toBeEnabled();
 });
});
