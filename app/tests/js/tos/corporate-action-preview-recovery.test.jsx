import React from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import api from '../../../resources/js/src/api';
import { showToast } from '../../../resources/js/src/toast';
import CorporateActionPage from '../../../resources/js/src/pages/CorporateActionPage';
import { PORTFOLIO_CHANGED } from '../../../resources/js/src/utils/portfolioEvents';

vi.mock('../../../resources/js/src/api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../resources/js/src/toast', () => ({ showToast: vi.fn() }));
vi.mock('../../../resources/js/src/components/StockAutocomplete', () => ({
    default: ({ id, value, onChange, onSelect }) => (
        <div>
            <input id={id} aria-label="Stock" value={value} onChange={(event) => onChange(event.target.value)} />
            <button type="button" onClick={() => onSelect({ id: 42, symbol: 'TCS', name: 'Tata Consultancy', exchange: 'NSE' })}>Select TCS</button>
            <button type="button" onClick={() => onSelect({ id: 43, symbol: 'INFY', name: 'Infosys', exchange: 'NSE' })}>Select INFY</button>
        </div>
    ),
}));

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};

const previewResponse = (quantity, actionType = 'split', blockingErrors = []) => ({
    data: {
        data: {
            action_type: actionType,
            blocking_errors: blockingErrors,
            warnings: [],
            adjustments: [],
            post_state: { quantity, avg_buy_price: 50, invested_amount: quantity * 50 },
        },
    },
});

const renderPage = () => render(
    <MemoryRouter initialEntries={['/corporate-action']}>
        <Routes><Route path="/corporate-action" element={<CorporateActionPage />} /></Routes>
    </MemoryRouter>,
);

const chooseTcsAndDate = async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Select TCS' }));
    fireEvent.change(screen.getByLabelText('Ex-date / record date', { exact: true }), { target: { value: '17-Oct-2026' } });
    await waitFor(() => expect(screen.getByLabelText('Ratio to')).toHaveValue(2));
};

const beginPreview = () => fireEvent.click(screen.getByRole('button', { name: 'Preview', exact: true }));
const previewRegion = () => screen.getByRole('region', { name: 'Corporate action preview' });
const selectProfile = (id) => {
    sessionStorage.setItem('portfolio_active_id', String(id));
    window.dispatchEvent(new CustomEvent(PORTFOLIO_CHANGED, { detail: { portfolioId: id } }));
};

beforeEach(() => {
    cleanup();
    vi.resetAllMocks();
    sessionStorage.clear();
    sessionStorage.setItem('portfolio_active_id', '1');
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});
afterEach(() => { cleanup(); vi.restoreAllMocks(); });

it.each([
    ['stock identity', () => fireEvent.click(screen.getByRole('button', { name: 'Select INFY' })), { stock_id: 43, symbol: 'INFY' }, false],
    ['action type', () => fireEvent.click(within(screen.getByRole('group', { name: 'Corporate action type' })).getByRole('button', { name: 'Bonus issue', exact: true })), { action_type: 'bonus' }, false],
    ['ratio-from raw value with the same numeric payload', () => fireEvent.change(screen.getByLabelText('Ratio from'), { target: { value: '01' } }), { ratio_from: 1 }, true],
    ['ratio-to raw value with the same numeric payload', () => fireEvent.change(screen.getByLabelText('Ratio to'), { target: { value: '02' } }), { ratio_to: 2 }, true],
    ['display date with the same ISO payload', () => fireEvent.change(screen.getByLabelText('Ex-date / record date', { exact: true }), { target: { value: '17-Oct-2026 ' } }), { ex_date: '2026-10-17' }, true],
    ['date value', () => fireEvent.change(screen.getByLabelText('Ex-date / record date', { exact: true }), { target: { value: '18-Oct-2026' } }), { ex_date: '2026-10-18' }, false],
    ['split scope', () => fireEvent.click(within(screen.getByRole('group', { name: 'Split transaction scope' })).getByRole('button', { name: 'On/before ex-date only', exact: true })), { split_scope: 'before_ex_date' }, false],
    ['raw notes with the same trimmed payload', () => fireEvent.change(screen.getByLabelText('Notes (optional)'), { target: { value: 'Evidence ' } }), { notes: 'Evidence' }, true],
])('does not display a pending preview after editing %s', async (_field, edit, expectedPayload, payloadUnchanged) => {
    const oldPreview = deferred();
    api.post.mockReturnValueOnce(oldPreview.promise).mockResolvedValueOnce(previewResponse(303, expectedPayload.action_type || 'split'));
    renderPage();
    await chooseTcsAndDate();
    fireEvent.change(screen.getByLabelText('Notes (optional)'), { target: { value: 'Evidence' } });

    beginPreview();
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    await edit();
    await act(async () => { oldPreview.resolve(previewResponse(101)); });
    expect(previewRegion()).not.toHaveTextContent('Quantity: 101');
    expect(previewRegion()).toHaveTextContent('Run preview to see affected transactions and warnings.');

    beginPreview();
    expect(await screen.findByText('Quantity: 303')).toBeVisible();
    expect(api.post).toHaveBeenCalledTimes(2);
    expect(api.post.mock.calls[1][1]).toMatchObject(expectedPayload);
    if (payloadUnchanged) expect(api.post.mock.calls[0][1]).toEqual(api.post.mock.calls[1][1]);
    expect(api.post.mock.calls.some(([path]) => path === '/corporate-actions')).toBe(false);
});

it('does not let an obsolete preview error or finally clear a newer preview request', async () => {
    const oldRequest = deferred(); const currentRequest = deferred();
    api.post.mockReturnValueOnce(oldRequest.promise).mockReturnValueOnce(currentRequest.promise);
    renderPage(); await chooseTcsAndDate();
    beginPreview(); await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    fireEvent.change(screen.getByLabelText('Ratio to'), { target: { value: '3' } });
    beginPreview(); await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(screen.getByRole('button', { name: 'Previewing…' })).toBeDisabled();

    await act(async () => { oldRequest.reject({ response: { data: { message: 'Stale preview error' } } }); });
    expect(screen.getByRole('button', { name: 'Previewing…' })).toBeDisabled();
    expect(showToast).not.toHaveBeenCalledWith('Stale preview error', 'danger');
    await act(async () => { currentRequest.resolve(previewResponse(303)); });
    expect(await screen.findByText('Quantity: 303')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Preview', exact: true })).toBeEnabled();
});

it('does not restore an obsolete preview after raw inputs change A-to-B-to-A', async () => {
    const oldPreview = deferred();
    api.post.mockReturnValueOnce(oldPreview.promise).mockResolvedValueOnce(previewResponse(404));
    renderPage(); await chooseTcsAndDate();
    fireEvent.change(screen.getByLabelText('Notes (optional)'), { target: { value: 'Evidence A' } });
    beginPreview(); await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    fireEvent.change(screen.getByLabelText('Notes (optional)'), { target: { value: 'Evidence B' } });
    fireEvent.change(screen.getByLabelText('Notes (optional)'), { target: { value: 'Evidence A' } });

    await act(async () => { oldPreview.resolve(previewResponse(101)); });
    expect(previewRegion()).not.toHaveTextContent('Quantity: 101');
    expect(previewRegion()).toHaveTextContent('Run preview to see affected transactions and warnings.');

    beginPreview();
    expect(await screen.findByText('Quantity: 404')).toBeVisible();
    expect(api.post).toHaveBeenCalledTimes(2);
    expect(api.post.mock.calls[1][1].notes).toBe('Evidence A');
});

it('invalidates across A-to-B-to-A profile changes without automatic preview requests', async () => {
    const oldA = deferred();
    api.post.mockReturnValueOnce(oldA.promise).mockResolvedValueOnce(previewResponse(404));
    renderPage(); await chooseTcsAndDate();
    beginPreview(); await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    act(() => selectProfile(2));
    act(() => selectProfile(1));
    expect(api.post).toHaveBeenCalledTimes(1);
    await act(async () => { oldA.resolve(previewResponse(101)); });
    expect(previewRegion()).not.toHaveTextContent('Quantity: 101');
    expect(api.post).toHaveBeenCalledTimes(1);

    beginPreview();
    expect(await screen.findByText('Quantity: 404')).toBeVisible();
    expect(api.post).toHaveBeenCalledTimes(2);
});

it('ignores a pending preview failure after unmount', async () => {
    const pending = deferred(); api.post.mockReturnValueOnce(pending.promise);
    renderPage(); await chooseTcsAndDate();
    beginPreview(); await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1));
    cleanup();
    await act(async () => { pending.reject({ response: { data: { message: 'Unmounted preview error' } } }); });
    expect(showToast).not.toHaveBeenCalledWith('Unmounted preview error', 'danger');
});

it('keeps an explicit preview request current through React StrictMode effect replay', async () => {
    api.post.mockResolvedValueOnce(previewResponse(202));
    render(<React.StrictMode><MemoryRouter initialEntries={['/corporate-action']}><Routes><Route path="/corporate-action" element={<CorporateActionPage />} /></Routes></MemoryRouter></React.StrictMode>);
    await chooseTcsAndDate();
    beginPreview();
    expect(await screen.findByText('Quantity: 202')).toBeVisible();
    expect(api.post).toHaveBeenCalledTimes(1);
});

it('keeps inputs after a same-input preview failure and permits explicit retry', async () => {
    api.post.mockRejectedValueOnce({ response: { data: { message: 'Preview unavailable' } } }).mockResolvedValueOnce(previewResponse(202));
    renderPage(); await chooseTcsAndDate();
    fireEvent.change(screen.getByLabelText('Notes (optional)'), { target: { value: 'Reviewed evidence' } });
    beginPreview();
    await waitFor(() => expect(showToast).toHaveBeenCalledWith('Preview unavailable', 'danger'));
    expect(screen.getByLabelText('Notes (optional)')).toHaveValue('Reviewed evidence');
    expect(screen.getByLabelText('Ratio to')).toHaveValue(2);
    beginPreview();
    expect(await screen.findByText('Quantity: 202')).toBeVisible();
    expect(api.post).toHaveBeenCalledTimes(2);
    expect(api.post.mock.calls[0][1]).toEqual(api.post.mock.calls[1][1]);
});

it('uses preview-only return and blocks application when the preview has blocking errors', async () => {
    api.post.mockResolvedValueOnce(previewResponse(202)).mockResolvedValueOnce(previewResponse(202, 'split', ['Blocked synthetic preview']));
    renderPage(); await chooseTcsAndDate();
    fireEvent.click(screen.getByRole('button', { name: 'Apply', exact: true }));
    await screen.findByText('Quantity: 202');
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenCalledWith('/corporate-actions/preview', expect.any(Object));
    expect(window.confirm).not.toHaveBeenCalled();

    beginPreview(); await screen.findByText('Blocked synthetic preview');
    fireEvent.click(screen.getByRole('button', { name: 'Apply', exact: true }));
    expect(showToast).toHaveBeenCalledWith('Blocked synthetic preview', 'danger');
    expect(window.confirm).not.toHaveBeenCalled();
    expect(api.post.mock.calls.some(([path]) => path === '/corporate-actions')).toBe(false);
});

it('keeps the existing confirmation boundary for a current preview', async () => {
    api.post.mockResolvedValueOnce(previewResponse(202)).mockResolvedValueOnce({ data: { data: { id: 31 } } });
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
    renderPage(); await chooseTcsAndDate();
    fireEvent.change(screen.getByLabelText('Notes (optional)'), { target: { value: 'Reviewed evidence' } });
    beginPreview(); await screen.findByText('Quantity: 202');

    fireEvent.click(screen.getByRole('button', { name: 'Apply', exact: true }));
    expect(confirm).toHaveBeenCalledWith('Apply 1:2 stock split for TCS? This updates your transaction ledger.');
    expect(api.post).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole('button', { name: 'Apply', exact: true }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(api.post.mock.calls[1][0]).toBe('/corporate-actions');
    expect(api.post.mock.calls[1][1]).toEqual(api.post.mock.calls[0][1]);
    expect(api.post.mock.calls[1][1]).toMatchObject({ stock_id: 42, symbol: 'TCS', notes: 'Reviewed evidence', split_scope: 'all' });
    expect(showToast).toHaveBeenCalledWith('Corporate action applied.');
});
