import React, { useCallback, useMemo, useRef, useState } from 'react';
import api from '../api';
import NumberInput from './NumberInput';
import SegmentToggle from './SegmentToggle';
import TransactionDateInput from './TransactionDateInput';
import { showToast } from '../toast';
import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';
import { calculateTransactionFees } from '../utils/feeCalculator';
import { parseBulkTransactionCsv } from '../utils/bulkTransactionCsv';
import {
    formatTransactionDateDisplay,
    getLocalTodayDateString,
    isTransactionDateInFuture,
    isValidTransactionDate,
    parseTransactionDateDisplay,
} from '../utils/transactionDate';

const SAMPLE_CSV = `Stock,Quantity,Average Price,Transaction Type
WELCORP,32,1520.00,BUY
MBAPL,88,565.27,BUY
SCHNEIDER,34,1449.53,BUY
SYRMA,29,1369.80,BUY`;

function createUuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `00000000-0000-4000-8000-${Date.now().toString(16).padStart(12, '0').slice(-12)}`;
}

function roundToTwoDecimals(value) {
    const num = Number(value);
    if (Number.isNaN(num)) {
        return '';
    }
    return (Math.round(num * 100) / 100).toFixed(2);
}

function rowFees(row, feeComponents) {
    return calculateTransactionFees({
        quantity: row.quantity,
        price: row.price,
        type: row.type,
        exchange: row.exchange,
        feeComponents,
    });
}

function rowIsValid(row, displayDate) {
    const iso = parseTransactionDateDisplay(displayDate);
    if (!row.symbol?.trim()) {
        return false;
    }
    const qty = Number(row.quantity);
    const price = Number(row.price);
    if (!Number.isInteger(qty) || qty < 1) {
        return false;
    }
    if (Number.isNaN(price) || price <= 0) {
        return false;
    }
    if (!isValidTransactionDate(iso) || isTransactionDateInFuture(iso)) {
        return false;
    }
    return row.type === 'buy' || row.type === 'sell';
}

function extractBulkErrors(error) {
    const data = error?.response?.data;
    const messages = [];
    if (data?.errors && typeof data.errors === 'object') {
        Object.entries(data.errors).forEach(([key, value]) => {
            const text = Array.isArray(value) ? value[0] : value;
            if (!text) {
                return;
            }
            const match = /^rows\.(\d+)\.(\w+)$/.exec(key);
            messages.push({
                field: key,
                index: match ? Number(match[1]) : null,
                message: String(text),
            });
        });
    }
    if (messages.length === 0 && data?.message) {
        messages.push({ message: data.message });
    }
    return messages;
}

export default function BulkTransactionImport({ feeComponents, onSaved }) {
    const [step, setStep] = useState('paste');
    const [csvText, setCsvText] = useState('');
    const [parseErrors, setParseErrors] = useState([]);
    const [rows, setRows] = useState([]);
    const [dateDisplays, setDateDisplays] = useState({});
    const [batchId, setBatchId] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [batchStatus, setBatchStatus] = useState(null);
    const [submitErrors, setSubmitErrors] = useState([]);
    const submitLockRef = useRef(false);

    const buildReviewState = useCallback((parsedRows) => {
        const today = getLocalTodayDateString();
        const displays = {};
        const enriched = parsedRows.map((row) => {
            const id = row.id && String(row.id).includes('-') ? row.id : createUuid();
            displays[id] = formatTransactionDateDisplay(today);
            return {
                ...row,
                id,
                exchange: 'NSE',
                transaction_date: today,
            };
        });
        setDateDisplays(displays);
        setRows(enriched);
        setBatchId(createUuid());
        setBatchStatus(null);
        setSubmitErrors([]);
    }, []);

    const handleParse = () => {
        const { rows: parsed, errors } = parseBulkTransactionCsv(csvText);
        setParseErrors(errors);
        if (parsed.length === 0) {
            return;
        }
        buildReviewState(parsed);
        setStep('review');
    };

    const updateRow = (id, patch) => {
        if (batchStatus === 'committed' || batchStatus === 'already_committed') {
            return;
        }
        setRows((prev) => prev.map((row) => (row.id === id ? { ...row, ...patch } : row)));
    };

    const updateRowDate = (id, iso, display) => {
        if (batchStatus === 'committed' || batchStatus === 'already_committed') {
            return;
        }
        setDateDisplays((prev) => ({ ...prev, [id]: display }));
        updateRow(id, { transaction_date: iso });
    };

    const removeRow = (id) => {
        if (batchStatus === 'committed' || batchStatus === 'already_committed') {
            return;
        }
        setRows((prev) => prev.filter((row) => row.id !== id));
        setDateDisplays((prev) => {
            const next = { ...prev };
            delete next[id];
            return next;
        });
    };

    const allRowsValid = useMemo(() => (
        rows.length > 0 && rows.every((row) => rowIsValid(row, dateDisplays[row.id]))
    ), [rows, dateDisplays]);

    const batchCompleted = batchStatus === 'committed' || batchStatus === 'already_committed';

    const handleSubmitAll = async () => {
        if (batchCompleted || submitLockRef.current || submitting) {
            return;
        }
        if (!allRowsValid) {
            showToast('Fix invalid rows before submitting.', 'danger');
            return;
        }
        if (!batchId) {
            showToast('Import batch is missing. Go back and parse the CSV again.', 'danger');
            return;
        }

        submitLockRef.current = true;
        setSubmitting(true);
        setSubmitErrors([]);
        setBatchStatus('submitting');

        const payloadRows = rows.map((row) => {
            const fees = rowFees(row, feeComponents);
            const iso = parseTransactionDateDisplay(dateDisplays[row.id]) || row.transaction_date;
            return {
                row_id: row.id,
                symbol: row.symbol.trim().toUpperCase(),
                exchange: row.exchange,
                type: row.type,
                quantity: Number(row.quantity),
                price: Number(row.price),
                fees: fees.total,
                transaction_date: iso,
            };
        });

        try {
            const response = await api.post('/transactions/bulk', {
                batch_id: batchId,
                rows: payloadRows,
            });
            const status = response.data?.status || 'committed';
            setBatchStatus(status);
            const count = response.data?.row_count ?? rows.length;
            showToast(
                status === 'already_committed'
                    ? `Batch already imported (${count} transaction(s)).`
                    : `Saved ${count} transaction(s).`,
                'success',
            );
            onSaved?.();
            notifyPortfolioDashboardRefresh();
        } catch (error) {
            setBatchStatus('failed');
            const errors = extractBulkErrors(error);
            setSubmitErrors(errors);
            showToast(
                error?.response?.data?.message || 'Import failed — no transactions were saved. Fix and retry.',
                'danger',
            );
        } finally {
            setSubmitting(false);
            submitLockRef.current = false;
        }
    };

    const handleBack = () => {
        if (submitting) {
            return;
        }
        setStep('paste');
        setBatchStatus(null);
        setSubmitErrors([]);
        setBatchId(null);
    };

    const handleStartNewImport = () => {
        setStep('paste');
        setCsvText('');
        setRows([]);
        setParseErrors([]);
        setDateDisplays({});
        setBatchId(null);
        setBatchStatus(null);
        setSubmitErrors([]);
    };

    if (step === 'paste') {
        return (
            <div className="card" id="bulk-transaction-import">
                <div className="card-header">Bulk import (CSV)</div>
                <div className="card-body d-grid gap-3">
                    <p className="text-muted small mb-0">
                        Paste CSV with columns:
                        {' '}
                        <strong>Stock, Quantity, Average Price, Transaction Type</strong>
                        . Header row is optional. Dates are set on the review step (default today).
                        Save commits
                        {' '}
                        <strong>all rows or nothing</strong>
                        .
                    </p>
                    <textarea
                        className="form-control font-monospace"
                        rows={8}
                        value={csvText}
                        onChange={(e) => setCsvText(e.target.value)}
                        placeholder={SAMPLE_CSV}
                        aria-label="Bulk transaction CSV"
                    />
                    <div className="d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="btn btn-outline-secondary btn-sm"
                            onClick={() => setCsvText(SAMPLE_CSV)}
                        >
                            Insert sample
                        </button>
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={handleParse}
                            disabled={!csvText.trim()}
                        >
                            Parse &amp; review
                        </button>
                    </div>
                    {parseErrors.length > 0 && (
                        <ul className="small text-danger mb-0">
                            {parseErrors.map((err) => (
                                <li key={err}>{err}</li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        );
    }

    return (
        <div className="card" id="bulk-transaction-import">
            <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Review bulk transactions</span>
                <span className="text-muted small">
                    {rows.length}
                    {' '}
                    row(s)
                    {batchCompleted ? ' · imported' : ''}
                </span>
            </div>
            <div className="card-body d-grid gap-3">
                {batchStatus === 'failed' && (
                    <div className="alert alert-warning py-2 mb-0 small" role="status">
                        Import failed — no transactions were committed. Correct the rows and retry
                        (same batch). Or go back to paste a new CSV (new batch).
                    </div>
                )}
                {batchCompleted && (
                    <div className="alert alert-success py-2 mb-0 small" role="status">
                        This batch is committed and cannot be submitted again.
                        Start a new import to add more transactions.
                    </div>
                )}

                <div className="table-responsive">
                    <table className="table table-sm align-middle lido-bulk-import-table mb-0">
                        <thead>
                            <tr>
                                <th>Stock</th>
                                <th>Qty</th>
                                <th>Avg price</th>
                                <th>Type</th>
                                <th>Exchange</th>
                                <th>Date</th>
                                <th>Fees (₹)</th>
                                <th aria-label="Actions" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => {
                                const fees = rowFees(row, feeComponents);
                                const displayDate = dateDisplays[row.id]
                                    ?? formatTransactionDateDisplay(row.transaction_date);
                                const isoDate = parseTransactionDateDisplay(displayDate)
                                    || row.transaction_date;
                                const dateInvalid = isTransactionDateInFuture(isoDate)
                                    || !isValidTransactionDate(isoDate);
                                const valid = rowIsValid(row, displayDate);
                                const locked = batchCompleted || submitting;

                                return (
                                    <tr key={row.id} className={valid ? '' : 'table-warning'}>
                                        <td>
                                            <input
                                                type="text"
                                                className="form-control form-control-sm"
                                                value={row.symbol}
                                                disabled={locked}
                                                onChange={(e) => updateRow(row.id, {
                                                    symbol: e.target.value.toUpperCase(),
                                                })}
                                                aria-label={`Stock symbol ${row.symbol}`}
                                            />
                                        </td>
                                        <td className="lido-bulk-import-narrow">
                                            <NumberInput
                                                className="form-control-sm"
                                                min="1"
                                                step="1"
                                                allowDecimals={false}
                                                value={row.quantity}
                                                disabled={locked}
                                                onChange={(e) => updateRow(row.id, {
                                                    quantity: e.target.value === ''
                                                        ? ''
                                                        : parseInt(e.target.value, 10),
                                                })}
                                                aria-label={`Quantity ${row.symbol}`}
                                            />
                                        </td>
                                        <td className="lido-bulk-import-narrow">
                                            <NumberInput
                                                className="form-control-sm"
                                                min="0.05"
                                                step="0.05"
                                                fixedDecimals={2}
                                                value={row.price}
                                                disabled={locked}
                                                onChange={(e) => updateRow(row.id, { price: e.target.value })}
                                                onBlur={(e) => updateRow(row.id, {
                                                    price: e.target.value === ''
                                                        ? ''
                                                        : roundToTwoDecimals(e.target.value),
                                                })}
                                                aria-label={`Price ${row.symbol}`}
                                            />
                                        </td>
                                        <td>
                                            <SegmentToggle
                                                compact
                                                ariaLabel={`Transaction type ${row.symbol}`}
                                                value={row.type}
                                                disabled={locked}
                                                onChange={(type) => updateRow(row.id, { type })}
                                                options={[
                                                    { value: 'buy', label: 'Buy' },
                                                    { value: 'sell', label: 'Sell' },
                                                ]}
                                            />
                                        </td>
                                        <td>
                                            <SegmentToggle
                                                compact
                                                ariaLabel={`Exchange ${row.symbol}`}
                                                value={row.exchange}
                                                disabled={locked}
                                                onChange={(exchange) => updateRow(row.id, { exchange })}
                                                options={[
                                                    { value: 'NSE', label: 'NSE' },
                                                    { value: 'BSE', label: 'BSE' },
                                                ]}
                                            />
                                        </td>
                                        <td className="lido-bulk-import-date">
                                            <TransactionDateInput
                                                id={`bulk-tx-date-${row.id}`}
                                                displayValue={displayDate}
                                                isoValue={isoDate}
                                                fallbackIso={row.transaction_date}
                                                invalid={dateInvalid}
                                                disabled={locked}
                                                onDisplayChange={(display) => {
                                                    if (locked) {
                                                        return;
                                                    }
                                                    setDateDisplays((prev) => ({
                                                        ...prev,
                                                        [row.id]: display,
                                                    }));
                                                    const iso = parseTransactionDateDisplay(display);
                                                    if (iso) {
                                                        updateRow(row.id, { transaction_date: iso });
                                                    }
                                                }}
                                                onIsoChange={(iso) => updateRowDate(
                                                    row.id,
                                                    iso,
                                                    formatTransactionDateDisplay(iso),
                                                )}
                                            />
                                        </td>
                                        <td className="text-nowrap">
                                            {roundToTwoDecimals(fees.total)}
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-danger"
                                                onClick={() => removeRow(row.id)}
                                                disabled={locked}
                                                aria-label={`Remove ${row.symbol}`}
                                            >
                                                ×
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {submitErrors.length > 0 && (
                    <ul className="small text-danger mb-0">
                        {submitErrors.map((item, idx) => (
                            <li key={`${item.row_id || item.field || 'err'}-${idx}`}>
                                {item.row_id ? `${item.row_id}: ` : ''}
                                {item.message || item.field}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="d-flex flex-wrap gap-2 align-items-center">
                    {!batchCompleted && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={handleSubmitAll}
                            disabled={!allRowsValid || submitting}
                        >
                            {submitting
                                ? 'Saving batch…'
                                : `Save all (${rows.length})`}
                        </button>
                    )}
                    {batchCompleted && (
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={handleStartNewImport}
                        >
                            New import
                        </button>
                    )}
                    <button
                        type="button"
                        className="btn btn-outline-secondary"
                        onClick={handleBack}
                        disabled={submitting}
                    >
                        Back to CSV
                    </button>
                </div>
            </div>
        </div>
    );
}
