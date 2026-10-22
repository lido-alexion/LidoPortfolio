import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import api from '../api';
import SegmentToggle from '../components/SegmentToggle';
import StockAutocomplete from '../components/StockAutocomplete';
import TransactionDateInput from '../components/TransactionDateInput';
import { DataTableCard } from '../components/DataTable';
import { showToast } from '../toast';
import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';
import {
    formatTransactionDateDisplay,
    getLocalTodayDateString,
} from '../utils/transactionDate';
import { formatTableInteger, formatTableMoney2 } from '../utils/tableFormat';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';
import { PORTFOLIO_CHANGED } from '../utils/portfolioEvents';

const SPLIT_SCOPE_OPTIONS = [
    { value: 'all', label: 'All transactions' },
    { value: 'before_ex_date', label: 'On/before ex-date only' },
];

function buildAdjustmentColumns() {
    return [
        {
            accessorKey: 'transaction_date',
            header: 'Date',
            cell: ({ getValue }) => formatTransactionDateDisplay(getValue()),
        },
        {
            accessorKey: 'type',
            header: 'Type',
            cell: ({ getValue }) => (getValue() === 'sell' ? 'Sell' : 'Buy'),
        },
        {
            id: 'old',
            header: 'Before (qty @ price)',
            cell: ({ row }) => `${formatTableInteger(row.original.old_quantity)} @ ${formatTableMoney2(row.original.old_price)}`,
        },
        {
            id: 'new',
            header: 'After (qty @ price)',
            cell: ({ row }) => `${formatTableInteger(row.original.new_quantity)} @ ${formatTableMoney2(row.original.new_price)}`,
        },
    ];
}

export default function CorporateActionPage() {
    const location = useLocation();
    const navigate = useNavigate();
    const prefill = location.state?.corporateActionStock || null;

    const [stock, setStock] = useState(() => ({
        stock_id: prefill?.stock_id || prefill?.id || '',
        symbol: prefill?.symbol || '',
        name: prefill?.name || '',
        exchange: prefill?.exchange || 'NSE',
    }));
    const [actionType, setActionType] = useState('split');
    const [ratioFrom, setRatioFrom] = useState('1');
    const [ratioTo, setRatioTo] = useState('2');
    const [exDate, setExDate] = useState(getLocalTodayDateString());
    const [exDateDisplay, setExDateDisplay] = useState(() => formatTransactionDateDisplay(getLocalTodayDateString()));
    const [splitScope, setSplitScope] = useState('all');
    const [notes, setNotes] = useState('');
    const [previewReceipt, setPreviewReceipt] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [applyLoading, setApplyLoading] = useState(false);
    const previewGenerationRef = useRef(0);
    const inputRevisionRef = useRef(0);
    const portfolioIdRef = useRef(getActivePortfolioId());
    const portfolioEpochRef = useRef(0);
    const mountedRef = useRef(false);

    const adjustmentColumns = useMemo(() => buildAdjustmentColumns(), []);

    const payload = useMemo(() => ({
        stock_id: stock.stock_id || undefined,
        symbol: stock.symbol || undefined,
        action_type: actionType,
        ratio_from: Number(ratioFrom),
        ratio_to: Number(ratioTo),
        ex_date: exDate,
        notes: notes.trim() || undefined,
        split_scope: actionType === 'split' ? splitScope : undefined,
    }), [stock, actionType, ratioFrom, ratioTo, exDate, notes, splitScope]);

    const canSubmit = Boolean(stock.stock_id && stock.symbol && ratioFrom && ratioTo && exDate);

    const invalidatePreview = useCallback(() => {
        inputRevisionRef.current += 1;
        previewGenerationRef.current += 1;
        setPreviewReceipt(null);
        setPreviewLoading(false);
    }, []);

    const syncPortfolioScope = useCallback(() => {
        if (!mountedRef.current) return;
        const activePortfolioId = getActivePortfolioId();
        if (activePortfolioId === portfolioIdRef.current) return;
        portfolioIdRef.current = activePortfolioId;
        portfolioEpochRef.current += 1;
        inputRevisionRef.current += 1;
        previewGenerationRef.current += 1;
        setPreviewReceipt(null);
        setPreviewLoading(false);
    }, []);

    useEffect(() => {
        mountedRef.current = true;
        const handlePortfolioChange = () => syncPortfolioScope();
        window.addEventListener(PORTFOLIO_CHANGED, handlePortfolioChange);
        return () => {
            window.removeEventListener(PORTFOLIO_CHANGED, handlePortfolioChange);
            mountedRef.current = false;
            portfolioEpochRef.current += 1;
            previewGenerationRef.current += 1;
        };
    }, [syncPortfolioScope]);

    const payloadKey = JSON.stringify(payload);
    const preview = previewReceipt
        && previewReceipt.payloadKey === payloadKey
        && previewReceipt.inputRevision === inputRevisionRef.current
        && previewReceipt.portfolioId === portfolioIdRef.current
        && previewReceipt.portfolioEpoch === portfolioEpochRef.current
        && previewReceipt.portfolioId === getActivePortfolioId()
        ? previewReceipt.data
        : null;

    const changeInput = (currentValue, nextValue, setter) => {
        if (currentValue === nextValue) return;
        invalidatePreview();
        setter(nextValue);
    };

    const loadPreview = useCallback(async () => {
        syncPortfolioScope();
        if (!canSubmit) {
            showToast('Select a stock and enter ratio details first.', 'warning');
            return;
        }
        const requestGeneration = ++previewGenerationRef.current;
        const requestInputRevision = inputRevisionRef.current;
        const requestPortfolioId = portfolioIdRef.current;
        const requestPortfolioEpoch = portfolioEpochRef.current;
        const requestPayloadKey = JSON.stringify(payload);
        const isCurrentRequest = () => {
            syncPortfolioScope();
            return mountedRef.current
                && requestGeneration === previewGenerationRef.current
                && requestInputRevision === inputRevisionRef.current
                && requestPortfolioId === portfolioIdRef.current
                && requestPortfolioEpoch === portfolioEpochRef.current
                && requestPortfolioId === getActivePortfolioId();
        };
        setPreviewLoading(true);
        try {
            const res = await api.post('/corporate-actions/preview', payload);
            if (isCurrentRequest()) {
                setPreviewReceipt({
                    data: res.data.data,
                    payloadKey: requestPayloadKey,
                    inputRevision: requestInputRevision,
                    portfolioId: requestPortfolioId,
                    portfolioEpoch: requestPortfolioEpoch,
                });
            }
        } catch (err) {
            if (isCurrentRequest()) {
                setPreviewReceipt(null);
                showToast(err?.response?.data?.message || 'Could not preview corporate action.', 'danger');
            }
        } finally {
            if (isCurrentRequest()) setPreviewLoading(false);
        }
    }, [canSubmit, payload, syncPortfolioScope]);

    const applyAction = async () => {
        if (!preview) {
            await loadPreview();
            return;
        }
        if (preview.blocking_errors?.length) {
            showToast(preview.blocking_errors[0], 'danger');
            return;
        }
        const ratioLabel = `${ratioFrom}:${ratioTo}`;
        const label = actionType === 'split'
            ? `Apply ${ratioLabel} stock split for ${stock.symbol}?`
            : `Apply ${ratioLabel} bonus for ${stock.symbol}?`;
        if (!window.confirm(`${label} This updates your transaction ledger.`)) {
            return;
        }
        setApplyLoading(true);
        try {
            await api.post('/corporate-actions', payload);
            notifyPortfolioDashboardRefresh();
            showToast('Corporate action applied.');
            navigate('/transactions', { state: { transactionSearch: stock.symbol } });
        } catch (err) {
            showToast(err?.response?.data?.message || 'Could not apply corporate action.', 'danger');
        } finally {
            setApplyLoading(false);
        }
    };

    return (
        <div className="row g-3">
            <div className="col-12 d-flex flex-wrap align-items-center gap-2">
                <h1 className="h5 mb-0">Corporate action</h1>
                <Link to="/holdings" className="btn btn-sm btn-outline-secondary ms-auto">Back to holdings</Link>
            </div>
            <div className="col-12">
                <p className="small text-muted mb-0">
                    This page applies stock splits and bonus issues only. A rights issue does not change existing holdings.
                    If you subscribed and received shares, record them as a normal buy on{' '}
                    <Link to="/transactions">Transactions</Link>
                    {' '}at the actual subscription price.
                </p>
            </div>

            <div className="col-12 col-lg-7">
                <div className="card">
                    <div className="card-header">Stock split or bonus issue</div>
                    <div className="card-body d-grid gap-3">
                        <div>
                            <label className="form-label" htmlFor="ca-stock">Stock</label>
                            <StockAutocomplete
                                id="ca-stock"
                                value={stock.symbol}
                                exchange={stock.exchange}
                                onChange={(symbol) => changeInput(
                                    `${stock.stock_id || ''}|${stock.symbol}`,
                                    `|${symbol}`,
                                    () => setStock((prev) => ({ ...prev, symbol, stock_id: '' })),
                                )}
                                onSelect={(selected) => {
                                    const nextStock = {
                                        stock_id: selected.id,
                                        symbol: selected.symbol,
                                        name: selected.name || '',
                                        exchange: selected.exchange || stock.exchange || 'NSE',
                                    };
                                    changeInput(
                                        `${stock.stock_id || ''}|${stock.symbol}|${stock.name}|${stock.exchange}`,
                                        `${nextStock.stock_id}|${nextStock.symbol}|${nextStock.name}|${nextStock.exchange}`,
                                        () => setStock(nextStock),
                                    );
                                }}
                                required
                            />
                        </div>

                        <SegmentToggle
                            label="Action type"
                            ariaLabel="Corporate action type"
                            value={actionType}
                            onChange={(value) => changeInput(actionType, value, setActionType)}
                            options={[
                                { value: 'split', label: 'Stock split' },
                                { value: 'bonus', label: 'Bonus issue' },
                            ]}
                        />

                        <div className="row g-2 align-items-end">
                            <div className="col-4">
                                <label className="form-label" htmlFor="ca-ratio-from">Ratio from</label>
                                <input
                                    id="ca-ratio-from"
                                    type="number"
                                    min="1"
                                    step="1"
                                    className="form-control"
                                    value={ratioFrom}
                                    onChange={(e) => changeInput(ratioFrom, e.target.value, setRatioFrom)}
                                />
                            </div>
                            <div className="col-auto pb-2 text-muted">:</div>
                            <div className="col-4">
                                <label className="form-label" htmlFor="ca-ratio-to">Ratio to</label>
                                <input
                                    id="ca-ratio-to"
                                    type="number"
                                    min="1"
                                    step="1"
                                    className="form-control"
                                    value={ratioTo}
                                    onChange={(e) => changeInput(ratioTo, e.target.value, setRatioTo)}
                                />
                            </div>
                            <div className="col-12">
                                <p className="small text-muted mb-0">
                                    {actionType === 'split'
                                        ? 'Example 1:2 split multiplies quantity by 2 and divides price by 2. Cost basis is preserved; average cost, stop, and trailing scale with the ratio. Position target (rupees) is kept so remaining BUY stays economically equivalent.'
                                        : 'Example 1:1 bonus adds one zero-cost share per share held on the record date (Indian tax style). Total invested cost is unchanged; average cost, stop, and trailing scale with the new quantity.'}
                                </p>
                            </div>
                        </div>

                        <div>
                            <label className="form-label" htmlFor="ca-ex-date">Ex-date / record date</label>
                            <TransactionDateInput
                                id="ca-ex-date"
                                displayValue={exDateDisplay}
                                isoValue={exDate}
                                onDisplayChange={(value) => changeInput(exDateDisplay, value, setExDateDisplay)}
                                onIsoChange={(value) => changeInput(exDate, value, setExDate)}
                            />
                        </div>

                        {actionType === 'split' ? (
                            <SegmentToggle
                                label="Split scope"
                                ariaLabel="Split transaction scope"
                                value={splitScope}
                                onChange={(value) => changeInput(splitScope, value, setSplitScope)}
                                options={SPLIT_SCOPE_OPTIONS}
                            />
                        ) : null}

                        <div>
                            <label className="form-label" htmlFor="ca-notes">Notes (optional)</label>
                            <textarea
                                id="ca-notes"
                                className="form-control"
                                rows={2}
                                value={notes}
                                onChange={(e) => changeInput(notes, e.target.value, setNotes)}
                            />
                        </div>

                        <div className="d-flex flex-wrap gap-2">
                            <button
                                type="button"
                                className="btn btn-outline-primary"
                                onClick={loadPreview}
                                disabled={!canSubmit || previewLoading || applyLoading}
                            >
                                {previewLoading ? 'Previewing…' : 'Preview'}
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={applyAction}
                                disabled={!canSubmit || previewLoading || applyLoading}
                            >
                                {applyLoading ? 'Applying…' : 'Apply'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div className="col-12 col-lg-5">
                <div className="card h-100" role="region" aria-label="Corporate action preview">
                    <div className="card-header">Preview</div>
                    <div className="card-body">
                        {!preview ? (
                            <p className="text-muted small mb-0">Run preview to see affected transactions and warnings.</p>
                        ) : (
                            <div className="d-grid gap-3">
                                {preview.blocking_errors?.length ? (
                                    <div className="alert alert-danger py-2 small mb-0" role="alert">
                                        {preview.blocking_errors.map((msg) => <div key={msg}>{msg}</div>)}
                                    </div>
                                ) : null}

                                {preview.warnings?.length ? (
                                    <div className="alert alert-warning py-2 small mb-0" role="status">
                                        {preview.warnings.map((msg) => <div key={msg}>{msg}</div>)}
                                    </div>
                                ) : null}

                                {actionType === 'bonus' && preview.proposed_buy ? (
                                    <div className="small">
                                        <div><strong>Eligible shares:</strong> {formatTableInteger(preview.eligible_quantity)}</div>
                                        <div><strong>Bonus shares:</strong> {formatTableInteger(preview.bonus_quantity)}</div>
                                        <div className="mt-2">
                                            <strong>Proposed buy:</strong>
                                            {' '}
                                            {formatTableInteger(preview.proposed_buy.quantity)}
                                            {' @ '}
                                            {formatTableMoney2(preview.proposed_buy.price)}
                                            {' on '}
                                            {formatTransactionDateDisplay(preview.proposed_buy.transaction_date)}
                                        </div>
                                    </div>
                                ) : null}

                                {preview.post_state ? (
                                    <div className="small">
                                        <div className="fw-semibold mb-1">After apply (estimated)</div>
                                        <div>Quantity: {formatTableInteger(preview.post_state.quantity)}</div>
                                        <div>Avg buy: {formatTableMoney2(preview.post_state.avg_buy_price)}</div>
                                        {preview.post_state.invested_amount != null ? (
                                            <div>Invested: {formatTableMoney2(preview.post_state.invested_amount)}</div>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {preview?.action_type === 'split' && preview.adjustments?.length ? (
                <div className="col-12">
                    <DataTableCard
                        title="Transaction adjustments"
                        columns={adjustmentColumns}
                        data={preview.adjustments}
                        storageKey="corporate-action-preview"
                        emptyMessage="No transactions to adjust."
                    />
                </div>
            ) : null}
        </div>
    );
}
