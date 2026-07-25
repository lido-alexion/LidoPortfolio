import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';

import api from '../api';

import { DataTableCard } from '../components/DataTable';

import NumberInput from '../components/NumberInput';

import SegmentToggle from '../components/SegmentToggle';

import StockAutocomplete from '../components/StockAutocomplete';

import { showToast } from '../toast';

import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';
import usePortfolioChanged from '../hooks/usePortfolioChanged';

import {

    getCachedStockValidation,

    setCachedStockValidation,

    stockValidationCacheKey,

} from '../utils/stockValidationCache';
import { stockExchangeLabel } from '../utils/exchangeDisplay';

import FeeBreakdownHint from '../components/FeeBreakdownHint';
import BulkTransactionImport from '../components/BulkTransactionImport';
import TransactionDateInput from '../components/TransactionDateInput';
import {
    calculateTransactionFees,
    DEFAULT_FEE_COMPONENTS,
    normalizeFeeComponents,
} from '../utils/feeCalculator';
import {
    formatTransactionDateDisplay,
    getLocalTodayDateString,
    isTransactionDateInFuture,
    isValidTransactionDate,
    parseTransactionDateDisplay,
} from '../utils/transactionDate';
import { buildTransactionTableColumns } from '../utils/transactionTableColumns';



const emptyForm = () => ({

    id: null,

    stock_id: '',

    symbol: '',

    name: '',

    exchange: 'NSE',

    type: 'buy',

    quantity: '',

    price: '',

    transaction_date: getLocalTodayDateString(),

    notes: '',

});



function roundToTwoDecimals(value) {

    const num = Number(value);

    if (Number.isNaN(num)) {

        return '';

    }

    return (Math.round(num * 100) / 100).toFixed(2);

}



function isPositiveInteger(value) {

    const num = Number(value);

    return Number.isInteger(num) && num >= 1;

}



function isValidMoneyField(value) {

    if (value === '' || value === null || value === undefined) {

        return false;

    }

    const num = Number(value);

    if (Number.isNaN(num) || num < 0) {

        return false;

    }

    return Math.round(num * 100) / 100 === num;

}



function isValidPrice(value) {

    return isValidMoneyField(value) && Number(value) > 0;

}



export default function TransactionsPage() {
    const location = useLocation();
    const navigate = useNavigate();

    const [transactions, setTransactions] = useState([]);

    const [loading, setLoading] = useState(true);

    const [form, setForm] = useState(emptyForm());

    const [selectedStock, setSelectedStock] = useState(null);

    const [symbolValidation, setSymbolValidation] = useState(null);

    const [validatingSymbol, setValidatingSymbol] = useState(false);

    const [submitting, setSubmitting] = useState(false);

    const validationTokenRef = useRef(0);

    const dateInputFocusedRef = useRef(false);

    const [transactionDateInput, setTransactionDateInput] = useState(() => (
        formatTransactionDateDisplay(getLocalTodayDateString())
    ));

    const [feeComponents, setFeeComponents] = useState(DEFAULT_FEE_COMPONENTS);

    const [stockSearch, setStockSearch] = useState('');

    const [entryMode, setEntryMode] = useState('single');



    const load = useCallback(async () => {
        setLoading(true);
        try {
            const txRes = await api.get('/transactions', { params: { scope: 'open', per_page: 500 } });
            setTransactions(txRes.data.data || []);
        } finally {
            setLoading(false);
        }
    }, []);

    const loadFeeSettings = async () => {
        const res = await api.get('/settings');
        setFeeComponents(normalizeFeeComponents(res.data.data?.fee_components));
    };



    useEffect(() => { load(); loadFeeSettings(); }, [load]);

    usePortfolioChanged(() => {
        load();
        loadFeeSettings();
    });

    useEffect(() => {
        const prefill = location.state?.sellPrefill;
        if (!prefill?.stock) {
            return;
        }

        const stock = prefill.stock;
        const exchange = stock.exchange || 'NSE';

        setEntryMode('single');
        setCachedStockValidation(exchange, stock.symbol, {
            valid: true,
            stock,
            source: 'local',
            cached: true,
        });
        setSelectedStock(stock);
        setSymbolValidation({
            valid: true,
            symbol: stock.symbol,
            exchange,
            source: 'local',
            cached: true,
            message: 'Loaded from holdings.',
        });
        setForm({
            ...emptyForm(),
            stock_id: String(stock.id),
            symbol: stock.symbol,
            name: stock.name || stock.symbol,
            exchange,
            type: 'sell',
            quantity: prefill.quantity,
            price: prefill.price != null && prefill.price > 0 ? roundToTwoDecimals(prefill.price) : '',
            transaction_date: getLocalTodayDateString(),
        });

        navigate('/transactions', { replace: true, state: {} });

        requestAnimationFrame(() => {
            document.getElementById('transaction-form-card')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }, [location.state, navigate]);

    useEffect(() => {
        const search = location.state?.transactionSearch;
        if (typeof search !== 'string' || !search.trim()) {
            return;
        }

        setStockSearch(search.trim());
        navigate('/transactions', { replace: true, state: {} });

        requestAnimationFrame(() => {
            document.getElementById('active-transactions-table')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }, [location.state, navigate]);

    useEffect(() => {

        if (dateInputFocusedRef.current) {

            return;

        }

        setTransactionDateInput(formatTransactionDateDisplay(form.transaction_date));

    }, [form.transaction_date]);



    useEffect(() => {

        if (form.id || !symbolValidation?.valid) {

            return;

        }

        const sym = form.symbol.trim().toUpperCase();

        if (symbolValidation.symbol !== sym) {

            validationTokenRef.current += 1;

            setValidatingSymbol(false);

            setSymbolValidation(null);

            setSelectedStock(null);

            setForm((prev) => ({ ...prev, stock_id: '', name: '' }));

        }

    }, [form.symbol, form.id, symbolValidation]);



    const applyValidatedStock = useCallback((stock, source, cached = false) => {

        setSelectedStock(stock);

        setForm((prev) => {

            setSymbolValidation({

                valid: true,

                symbol: stock.symbol,

                exchange: stock.exchange || 'NSE',

                source,

                cached,

                message: cached ? 'Found in local master.' : `Validated via ${source}.`,

            });

            return {

                ...prev,

                stock_id: String(stock.id),

                symbol: stock.symbol,

                name: stock.name,

            };

        });

    }, []);



    const createTx = async (e) => {

        e.preventDefault();

        const txDate = parseTransactionDateDisplay(transactionDateInput);

        if (!isValidTransactionDate(txDate)) {
            showToast('Enter a valid transaction date (dd-mmm-yyyy) that is not in the future', 'danger');
            return;
        }

        const payload = { ...buildPayload(), transaction_date: txDate };



        if (!form.id && !isSymbolValidated) {

            showToast('Validate the stock symbol before saving', 'danger');

            return;

        }

        setSubmitting(true);

        try {

            if (form.id) {

                await api.put(`/transactions/${form.id}`, payload);

                showToast('Transaction updated');

                setForm(emptyForm());

                setSelectedStock(null);

                setSymbolValidation(null);

            } else {

                await api.post('/transactions', payload);

                showToast('Transaction saved');

                setForm((prev) => ({ ...prev, price: '' }));

            }

            await load();

            notifyPortfolioDashboardRefresh();

        } finally {

            setSubmitting(false);

        }

    };



    const resolvedExchange = form.exchange || selectedStock?.exchange || 'NSE';

    const feeCalculation = useMemo(() => calculateTransactionFees({
        quantity: form.quantity,
        price: form.price,
        type: form.type,
        exchange: resolvedExchange,
        feeComponents,
    }), [form.quantity, form.price, form.type, resolvedExchange, feeComponents]);

    const buildPayload = () => {

        const base = {

            type: form.type,

            quantity: Number(form.quantity),

            price: Number(form.price),

            fees: feeCalculation.total,

            transaction_date: form.transaction_date,

            notes: form.notes,

        };



        if (form.id) {

            return { ...base, stock_id: form.stock_id };

        }



        if (selectedStock?.id) {

            return {

                ...base,

                stock_id: selectedStock.id,

            };

        }



        const symbol = form.symbol.trim().toUpperCase();

        return {

            ...base,

            symbol,

            name: form.name.trim() || selectedStock?.name || symbol,

            exchange: form.exchange || selectedStock?.exchange || 'NSE',

        };

    };



    const editTx = useCallback((tx) => {

        setForm({

            id: tx.id,

            stock_id: String(tx.stock_id),

            symbol: tx.stock?.symbol || '',

            name: tx.stock?.name || '',

            exchange: tx.stock?.exchange || 'NSE',

            type: tx.type,

            quantity: Number(tx.quantity),

            price: roundToTwoDecimals(tx.price),

            transaction_date: tx.transaction_date,

            notes: tx.notes || '',

        });

        setSelectedStock(tx.stock || null);

        setSymbolValidation(null);

    }, []);



    useEffect(() => {
        const tx = location.state?.editTransaction;
        if (!tx) {
            return;
        }
        setEntryMode('single');
        editTx(tx);
        navigate('/transactions', { replace: true, state: {} });
        requestAnimationFrame(() => {
            document.getElementById('transaction-form-card')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }, [location.state, editTx, navigate]);



    const deleteTx = useCallback(async (id) => {
        if (!window.confirm('Delete this transaction? If it came from a TOS recommendation fill, that recommendation will reopen for review.')) {
            return;
        }
        try {
            const { data } = await api.delete(`/transactions/${id}`);
            const msg = data?.message || 'Transaction deleted';
            showToast(msg, data?.tos?.recommendation_reopened ? 'success' : undefined);
            await load();
            notifyPortfolioDashboardRefresh();
        } catch (e) {
            showToast(e?.response?.data?.message || e?.response?.data?.error?.message || e.message || 'Delete failed', 'danger');
        }
    }, [load]);



    const handleStockSelect = (stock) => {
        const listingExchange = stock.exchange || 'NSE';

        setCachedStockValidation(listingExchange, stock.symbol, {

            valid: true,

            stock,

            source: 'local',

            cached: true,

        });

        applyValidatedStock(stock, 'local', true);

    };



    const handleSymbolChange = (symbol) => {

        setForm((prev) => {

            const normalized = symbol.trim().toUpperCase();

            const changed = normalized !== prev.symbol.trim().toUpperCase();

            if (!changed) {

                return { ...prev, symbol };

            }

            return {

                ...prev,

                symbol,

                stock_id: '',

                name: '',

            };

        });

    };



    const handleExchangeChange = (exchange) => {

        setForm((prev) => ({

            ...prev,

            exchange,

        }));

    };



    const validateSymbol = async () => {

        const symbol = form.symbol.trim().toUpperCase();

        if (symbol.length < 2) {

            showToast('Enter at least 2 characters for the symbol', 'danger');

            return;

        }



        const token = ++validationTokenRef.current;

        setValidatingSymbol(true);

        setSymbolValidation(null);



        try {

            const searchRes = await api.get('/stocks/search', {

                params: { q: symbol, limit: 20 },

                skipErrorToast: true,

            });

            if (token !== validationTokenRef.current) {

                return;

            }

            const exactMatches = (searchRes.data?.data || []).filter(

                (row) => String(row.symbol || '').toUpperCase() === symbol,

            );

            if (exactMatches.length > 0) {

                const stock = exactMatches.find((row) => row.exchange === 'NSE') || exactMatches[0];

                const listingExchange = stock.exchange || 'NSE';

                setCachedStockValidation(listingExchange, symbol, {

                    valid: true,

                    stock,

                    source: 'local',

                    cached: true,

                });

                applyValidatedStock(stock, 'local', true);

                return;

            }

            const feeExchange = form.exchange || 'NSE';

            const exchangesToTry = feeExchange === 'BSE' ? ['BSE', 'NSE'] : ['NSE', 'BSE'];

            let lastError = null;

            for (const exchange of exchangesToTry) {

                const cached = getCachedStockValidation(exchange, symbol);

                if (cached?.valid && cached.stock) {

                    applyValidatedStock(cached.stock, cached.source || 'local', true);

                    return;

                }

                try {

                    const res = await api.post('/stocks/validate', {

                        symbol,

                        exchange,

                        check_only: true,

                    });

                    if (token !== validationTokenRef.current) {

                        return;

                    }

                    const stock = res.data.data;

                    const entry = {

                        valid: true,

                        stock,

                        source: res.data.source,

                        cached: Boolean(res.data.meta?.cached),

                    };

                    setCachedStockValidation(exchange, symbol, entry);

                    applyValidatedStock(stock, res.data.source, entry.cached);

                    return;

                } catch (err) {

                    lastError = err;

                }

            }

            if (token !== validationTokenRef.current) {

                return;

            }

            const errors = lastError?.response?.data?.errors;

            const message = Array.isArray(errors) ? errors[0] : 'Symbol validation failed';

            setSymbolValidation({

                valid: false,

                symbol,

                exchange: form.exchange || 'NSE',

                message: typeof message === 'string' ? message : 'Symbol validation failed',

            });

            setSelectedStock(null);

            setForm((prev) => ({ ...prev, stock_id: '', name: '' }));

        } catch (err) {

            if (token !== validationTokenRef.current) {

                return;

            }

            const message = err?.response?.data?.message || 'Symbol validation failed';

            setSymbolValidation({

                valid: false,

                symbol,

                exchange: form.exchange || 'NSE',

                message,

            });

            setSelectedStock(null);

            setForm((prev) => ({ ...prev, stock_id: '', name: '' }));

        } finally {

            if (token === validationTokenRef.current) {

                setValidatingSymbol(false);

            }

        }

    };



    const isSymbolValidated = useMemo(() => {

        if (form.id) {

            return true;

        }

        if (!symbolValidation?.valid) {

            return false;

        }

        return symbolValidation.symbol === form.symbol.trim().toUpperCase();

    }, [form.id, form.symbol, symbolValidation]);



    const resolvedTransactionDate = useMemo(

        () => parseTransactionDateDisplay(transactionDateInput),

        [transactionDateInput],

    );

    const transactionDateIsFuture = isTransactionDateInFuture(resolvedTransactionDate);

    const canSubmit = useMemo(() => {

        if (!isValidTransactionDate(resolvedTransactionDate)) {

            return false;

        }

        if (!isPositiveInteger(form.quantity)) {

            return false;

        }

        if (!isValidPrice(form.price)) {

            return false;

        }

        if (!form.id && !isSymbolValidated) {

            return false;

        }

        return true;

    }, [form, isSymbolValidated, resolvedTransactionDate]);

    const filteredTransactions = useMemo(() => {
        const query = stockSearch.trim().toLowerCase();
        if (!query) {
            return transactions;
        }
        return transactions.filter((tx) => {
            const symbol = (tx.stock?.symbol || '').toLowerCase();
            const name = (tx.stock?.name || '').toLowerCase();
            return symbol.includes(query) || name.includes(query);
        });
    }, [transactions, stockSearch]);

    const transactionTableEmptyMessage = useMemo(() => {
        if (stockSearch.trim() && filteredTransactions.length === 0) {
            return 'No transactions match this search.';
        }
        return 'No transactions for open holdings.';
    }, [stockSearch, filteredTransactions.length]);



    const columns = useMemo(
        () => buildTransactionTableColumns({ onEdit: editTx, onDelete: deleteTx }),
        [editTx, deleteTx],
    );



    return (

        <div className="row g-3">

            <div className="col-12">
                <SegmentToggle
                    label="Add transactions"
                    ariaLabel="Transaction entry mode"
                    value={entryMode}
                    onChange={setEntryMode}
                    options={[
                        { value: 'single', label: 'Single' },
                        { value: 'bulk', label: 'Bulk (CSV)' },
                    ]}
                />
            </div>

            <div className="col-12">
                {entryMode === 'bulk' ? (
                    <BulkTransactionImport feeComponents={feeComponents} onSaved={load} />
                ) : (
                <div className="card lido-transaction-form-card" id="transaction-form-card">

                    <div className="card-header">{form.id ? 'Edit Transaction' : 'Add Transaction'}</div>

                    <div className="card-body">

                        <form onSubmit={createTx} className="d-grid gap-3">

                            {form.id ? (

                                <div>

                                    <label className="form-label">Stock</label>

                                    <input

                                        className="form-control"

                                        value={`${form.symbol} — ${form.name}`}

                                        readOnly

                                    />

                                </div>

                            ) : (

                                <>

                                    <div className="lido-transaction-toggles-row">
                                        <SegmentToggle
                                            label="Exchange"
                                            ariaLabel="Fee exchange"
                                            value={form.exchange}
                                            onChange={handleExchangeChange}
                                            options={[
                                                { value: 'NSE', label: 'NSE+' },
                                                { value: 'BSE', label: 'BSE' },
                                            ]}
                                        />
                                        <SegmentToggle
                                            label="Type"
                                            ariaLabel="Transaction type"
                                            className="lido-transaction-type-toggle"
                                            value={form.type}
                                            onChange={(type) => setForm({ ...form, type })}
                                            options={[
                                                { value: 'buy', label: 'Buy' },
                                                { value: 'sell', label: 'Sell' },
                                            ]}
                                        />
                                    </div>

                                    <div>

                                        <label className="form-label" htmlFor="stock-symbol-input">

                                            Stock symbol

                                        </label>

                                        <div className="d-flex gap-2 align-items-start">

                                            <div className="flex-grow-1">

                                                <StockAutocomplete

                                                    id="stock-symbol-input"

                                                    hideLabel

                                                    value={form.symbol}

                                                    exchange={null}

                                                    onChange={handleSymbolChange}

                                                    onSelect={handleStockSelect}
                                                    required
                                                    placeholder="e.g. INFY or company name"
                                                />

                                            </div>

                                            <button

                                                type="button"

                                                className="btn btn-outline-secondary datatable-col-menu-btn mt-0"

                                                style={{ minWidth: '2.75rem' }}

                                                onClick={validateSymbol}

                                                disabled={validatingSymbol || form.symbol.trim().length < 2}

                                                title="Validate symbol"

                                                aria-label="Validate symbol"

                                            >

                                                {validatingSymbol ? '…' : '✓'}

                                            </button>

                                        </div>

                                        {symbolValidation && !symbolValidation.valid && (

                                            <div className="form-text text-danger">

                                                {symbolValidation.message}

                                            </div>

                                        )}

                                        {symbolValidation?.valid && selectedStock && (

                                            <div className="form-text text-success">

                                                {symbolValidation.message}

                                                {' '}

                                                ({stockExchangeLabel(selectedStock)})

                                            </div>

                                        )}

                                        {!symbolValidation && form.symbol.trim().length >= 2 && (

                                            <div className="form-text text-muted">

                                                Select from suggestions or click validate.

                                            </div>

                                        )}

                                    </div>

                                </>

                            )}

                            {form.id && (
                                <div className="lido-transaction-toggles-row">
                                    <SegmentToggle
                                        label="Type"
                                        ariaLabel="Transaction type"
                                        className="lido-transaction-type-toggle"
                                        value={form.type}
                                        onChange={(type) => setForm({ ...form, type })}
                                        options={[
                                            { value: 'buy', label: 'Buy' },
                                            { value: 'sell', label: 'Sell' },
                                        ]}
                                    />
                                </div>
                            )}

                            <div>
                                <label className="form-label" htmlFor="tx-quantity">Quantity</label>
                                <NumberInput
                                    id="tx-quantity"
                                    min="1"
                                    step="1"
                                    allowDecimals={false}
                                    placeholder="e.g. 10"
                                    value={form.quantity}
                                    onChange={(e) => setForm({
                                        ...form,
                                        quantity: e.target.value === '' ? '' : parseInt(e.target.value, 10),
                                    })}
                                    required
                                />

                            </div>



                            <div>

                                <label className="form-label" htmlFor="tx-price">Price</label>

                                <NumberInput
                                    id="tx-price"
                                    min="0.05"
                                    step="0.05"
                                    fixedDecimals={2}
                                    placeholder="0.00"
                                    value={form.price}
                                    onChange={(e) => setForm({ ...form, price: e.target.value })}
                                    onBlur={(e) => setForm({
                                        ...form,
                                        price: e.target.value === '' ? '' : roundToTwoDecimals(e.target.value),
                                    })}
                                    required
                                />

                            </div>



                            <div>

                                <label id="tx-fees-label" className="form-label">
                                    Fees (₹)
                                </label>

                                <div className="d-flex align-items-center gap-1">
                                    <div
                                        id="tx-fees"
                                        className="form-control lido-fees-readonly"
                                        role="status"
                                        aria-labelledby="tx-fees-label"
                                        aria-describedby="tx-fees-help"
                                    >
                                        {roundToTwoDecimals(feeCalculation.total)}
                                    </div>
                                    <FeeBreakdownHint
                                        id="tx-fees-hint"
                                        breakdown={feeCalculation.breakdown}
                                        total={feeCalculation.total}
                                    />
                                </div>
                                <div id="tx-fees-help" className="form-text">
                                    Auto-calculated from Settings → fee components using the NSE+/BSE toggle above.
                                </div>

                            </div>



                            <div>

                                <label className="form-label" htmlFor="tx-date">Transaction date</label>

                                <TransactionDateInput
                                    id="tx-date"
                                    displayValue={transactionDateInput}
                                    isoValue={form.transaction_date}
                                    fallbackIso={form.transaction_date}
                                    invalid={transactionDateIsFuture}
                                    describedBy={transactionDateIsFuture ? 'tx-date-error' : undefined}
                                    required
                                    onDisplayChange={setTransactionDateInput}
                                    onIsoChange={(iso) => setForm((prev) => ({ ...prev, transaction_date: iso }))}
                                    onFocus={() => { dateInputFocusedRef.current = true; }}
                                    onBlur={() => { dateInputFocusedRef.current = false; }}
                                />

                                {transactionDateIsFuture && (

                                    <div id="tx-date-error" className="invalid-feedback d-block">

                                        Transaction date cannot be in the future.

                                    </div>

                                )}

                            </div>



                            <div>

                                <label className="form-label" htmlFor="tx-notes">Notes</label>

                                <textarea

                                    id="tx-notes"

                                    className="form-control"

                                    rows={2}

                                    value={form.notes}

                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                    placeholder="Optional notes"
                                />

                            </div>



                            <button className="btn btn-primary" type="submit" disabled={!canSubmit || submitting}>

                                {submitting
                                    ? (form.id ? 'Updating...' : 'Adding...')
                                    : (form.id ? 'Update Transaction' : 'Save Transaction')}

                            </button>

                            {form.id && (

                                <button

                                    type="button"

                                    className="btn btn-outline-secondary"

                                    onClick={() => {

                                        setForm(emptyForm());

                                        setSelectedStock(null);

                                        setSymbolValidation(null);

                                    }}

                                >

                                    Cancel Edit

                                </button>

                            )}

                        </form>

                    </div>

                </div>
                )}
            </div>

            <div className="col-12" id="active-transactions-table">

                <DataTableCard

                    title="Active transactions"

                    columns={columns}

                    data={filteredTransactions}

                    storageKey="transactions"

                    loading={loading}

                    emptyMessage={transactionTableEmptyMessage}

                    headerExtra={(
                        <div className="d-flex align-items-center gap-2">
                            <input
                                type="search"
                                className="form-control form-control-sm lido-table-search"
                                placeholder="Search symbol or name"
                                value={stockSearch}
                                onChange={(event) => setStockSearch(event.target.value)}
                                aria-label="Search transactions by stock symbol or name"
                            />
                            <Link
                                className="btn btn-sm btn-outline-secondary text-nowrap"
                                to="/corporate-action"
                                state={form.symbol ? {
                                    corporateActionStock: {
                                        stock_id: form.stock_id,
                                        symbol: form.symbol,
                                        name: form.name,
                                        exchange: form.exchange,
                                    },
                                } : undefined}
                            >
                                Corporate action
                            </Link>
                            <Link
                                className="btn btn-sm btn-outline-secondary text-nowrap"
                                to="/transactions/closed"
                            >
                                Squared-off
                            </Link>
                        </div>
                    )}

                />

            </div>

        </div>

    );

}


