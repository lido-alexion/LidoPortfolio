import React, { useCallback, useMemo, useState } from 'react';
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

export default function BulkTransactionImport({ feeComponents, onSaved }) {
    const [step, setStep] = useState('paste');
    const [csvText, setCsvText] = useState('');
    const [parseErrors, setParseErrors] = useState([]);
    const [rows, setRows] = useState([]);
    const [dateDisplays, setDateDisplays] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [submitProgress, setSubmitProgress] = useState(null);

    const buildReviewState = useCallback((parsedRows) => {
        const today = getLocalTodayDateString();
        const displays = {};
        const enriched = parsedRows.map((row) => {
            displays[row.id] = formatTransactionDateDisplay(today);
            return {
                ...row,
                exchange: 'NSE',
                transaction_date: today,
            };
        });
        setDateDisplays(displays);
        setRows(enriched);
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
        setRows((prev) => prev.map((row) => (row.id === id ? { ...row, ...patch } : row)));
    };

    const updateRowDate = (id, iso, display) => {
        setDateDisplays((prev) => ({ ...prev, [id]: display }));
        updateRow(id, { transaction_date: iso });
    };

    const removeRow = (id) => {
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

    const handleSubmitAll = async () => {
        if (!allRowsValid) {
            showToast('Fix invalid rows before submitting.', 'danger');
            return;
        }

        setSubmitting(true);
        setSubmitProgress({ done: 0, total: rows.length, failed: [] });

        let saved = 0;
        const failed = [];

        for (let index = 0; index < rows.length; index += 1) {
            const row = rows[index];
            const fees = rowFees(row, feeComponents);
            const iso = parseTransactionDateDisplay(dateDisplays[row.id]) || row.transaction_date;

            try {
                await api.post('/transactions', {
                    symbol: row.symbol.trim().toUpperCase(),
                    exchange: row.exchange,
                    type: row.type,
                    quantity: Number(row.quantity),
                    price: Number(row.price),
                    fees: fees.total,
                    transaction_date: iso,
                });
                saved += 1;
            } catch (error) {
                const message = error?.response?.data?.message
                    || error?.response?.data?.errors?.quantity?.[0]
                    || error?.response?.data?.errors?.symbol?.[0]
                    || `Row ${index + 1} (${row.symbol}) failed.`;
                failed.push({ symbol: row.symbol, message });
            }

            setSubmitProgress({ done: index + 1, total: rows.length, failed: [...failed] });
        }

        setSubmitting(false);

        if (failed.length === 0) {
            showToast(`Saved ${saved} transaction(s).`, 'success');
            setStep('paste');
            setCsvText('');
            setRows([]);
            setParseErrors([]);
            onSaved?.();
            notifyPortfolioDashboardRefresh();
            return;
        }

        showToast(
            `Saved ${saved} of ${rows.length}. ${failed.length} failed — see details below.`,
            saved > 0 ? 'warning' : 'danger',
        );
        setSubmitProgress({ done: rows.length, total: rows.length, failed });
    };

    const handleBack = () => {
        setStep('paste');
        setSubmitProgress(null);
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
                        . Header row is optional.
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
                </span>
            </div>
            <div className="card-body d-grid gap-3">
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

                                return (
                                    <tr key={row.id} className={valid ? '' : 'table-warning'}>
                                        <td>
                                            <input
                                                type="text"
                                                className="form-control form-control-sm"
                                                value={row.symbol}
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
                                                onDisplayChange={(display) => {
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

                {submitProgress?.failed?.length > 0 && (
                    <ul className="small text-danger mb-0">
                        {submitProgress.failed.map((item) => (
                            <li key={`${item.symbol}-${item.message}`}>
                                {item.symbol}
                                :
                                {' '}
                                {item.message}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="d-flex flex-wrap gap-2 align-items-center">
                    <button
                        type="button"
                        className="btn btn-primary"
                        onClick={handleSubmitAll}
                        disabled={!allRowsValid || submitting}
                    >
                        {submitting
                            ? `Saving… (${submitProgress?.done ?? 0}/${submitProgress?.total ?? rows.length})`
                            : `Save all (${rows.length})`}
                    </button>
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
