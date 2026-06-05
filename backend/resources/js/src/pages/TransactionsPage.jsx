import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';

import api from '../api';

import { DataTableCard } from '../components/DataTable';

import NumberInput from '../components/NumberInput';

import SegmentToggle from '../components/SegmentToggle';

import StockAutocomplete from '../components/StockAutocomplete';

import { showToast } from '../toast';

import { notifyPortfolioDashboardRefresh } from '../utils/portfolioEvents';

import {

    getCachedStockValidation,

    setCachedStockValidation,

    stockValidationCacheKey,

} from '../utils/stockValidationCache';

import { formatTableInteger, formatTableMoney2 } from '../utils/tableFormat';
import {
    formatTransactionDateDisplay,
    getLocalTodayDateString,
    isTransactionDateInFuture,
    isValidTransactionDate,
    parseTransactionDateDisplay,
} from '../utils/transactionDate';



const emptyForm = () => ({

    id: null,

    stock_id: '',

    symbol: '',

    name: '',

    exchange: 'NSE',

    type: 'buy',

    quantity: 1,

    price: '',

    brokerage: '0.00',

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

    const [form, setForm] = useState(emptyForm());

    const [selectedStock, setSelectedStock] = useState(null);

    const [symbolValidation, setSymbolValidation] = useState(null);

    const [validatingSymbol, setValidatingSymbol] = useState(false);

    const validationTokenRef = useRef(0);

    const dateInputFocusedRef = useRef(false);

    const [transactionDateInput, setTransactionDateInput] = useState(() => (
        formatTransactionDateDisplay(getLocalTodayDateString())
    ));



    const load = async () => {

        const txRes = await api.get('/transactions', { params: { per_page: 500 } });

        setTransactions(txRes.data.data || []);

    };



    useEffect(() => { load(); }, []);

    useEffect(() => {
        const prefill = location.state?.sellPrefill;
        if (!prefill?.stock) {
            return;
        }

        const stock = prefill.stock;
        const exchange = stock.exchange || 'NSE';

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
            brokerage: '0.00',
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

        if (dateInputFocusedRef.current) {

            return;

        }

        setTransactionDateInput(formatTransactionDateDisplay(form.transaction_date));

    }, [form.transaction_date]);



    const resetSymbolValidation = useCallback(() => {

        validationTokenRef.current += 1;

        setValidatingSymbol(false);

        setSymbolValidation(null);

        setSelectedStock(null);

        setForm((prev) => ({ ...prev, stock_id: '', name: '' }));

    }, []);



    useEffect(() => {

        if (form.id || !symbolValidation?.valid) {

            return;

        }

        const sym = form.symbol.trim().toUpperCase();

        if (symbolValidation.symbol !== sym || symbolValidation.exchange !== form.exchange) {

            validationTokenRef.current += 1;

            setValidatingSymbol(false);

            setSymbolValidation(null);

            setSelectedStock(null);

            setForm((prev) => ({ ...prev, stock_id: '', name: '' }));

        }

    }, [form.symbol, form.exchange, form.id, symbolValidation]);



    const applyValidatedStock = useCallback((stock, source, cached = false) => {

        setSelectedStock(stock);

        setForm((prev) => ({

            ...prev,

            stock_id: String(stock.id),

            symbol: stock.symbol,

            name: stock.name,

            exchange: stock.exchange || prev.exchange,

        }));

        setSymbolValidation({

            valid: true,

            symbol: stock.symbol,

            exchange: stock.exchange || 'NSE',

            source,

            cached,

            message: cached ? 'Found in local master.' : `Validated via ${source}.`,

        });

    }, [form.exchange]);



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



        if (form.id) {

            await api.put(`/transactions/${form.id}`, payload);

            showToast('Transaction updated');

        } else {

            await api.post('/transactions', payload);

            showToast('Transaction saved');

        }

        setForm(emptyForm());

        setSelectedStock(null);

        setSymbolValidation(null);

        await load();

        notifyPortfolioDashboardRefresh();

    };



    const buildPayload = () => {

        const base = {

            type: form.type,

            quantity: Number(form.quantity),

            price: Number(form.price),

            brokerage: Number(form.brokerage || 0),

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

            brokerage: roundToTwoDecimals(tx.brokerage || 0),

            transaction_date: tx.transaction_date,

            notes: tx.notes || '',

        });

        setSelectedStock(tx.stock || null);

        setSymbolValidation(null);

    }, []);



    const deleteTx = useCallback(async (id) => {

        if (!window.confirm('Delete this transaction?')) {

            return;

        }

        await api.delete(`/transactions/${id}`);

        showToast('Transaction deleted');

        await load();

        notifyPortfolioDashboardRefresh();

    }, []);



    const handleStockSelect = (stock) => {
        setCachedStockValidation(stock.exchange || form.exchange, stock.symbol, {

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

        resetSymbolValidation();

        setForm((prev) => ({

            ...prev,

            exchange,

            stock_id: '',

        }));

    };



    const validateSymbol = async () => {

        const symbol = form.symbol.trim().toUpperCase();

        if (symbol.length < 2) {

            showToast('Enter at least 2 characters for the symbol', 'danger');

            return;

        }



        const exchange = form.exchange || 'NSE';

        const cached = getCachedStockValidation(exchange, symbol);

        if (cached?.valid && cached.stock) {

            applyValidatedStock(cached.stock, cached.source || 'local', true);

            return;

        }



        const token = ++validationTokenRef.current;

        setValidatingSymbol(true);

        setSymbolValidation(null);



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

        } catch (err) {

            if (token !== validationTokenRef.current) {

                return;

            }

            const errors = err?.response?.data?.errors;

            const message = Array.isArray(errors) ? errors[0] : 'Symbol validation failed';

            setSymbolValidation({

                valid: false,

                symbol,

                exchange,

                message,

            });

            showToast(message, 'danger');

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

        return symbolValidation.symbol === form.symbol.trim().toUpperCase()

            && symbolValidation.exchange === form.exchange;

    }, [form.id, form.symbol, form.exchange, symbolValidation]);



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

        if (!isValidMoneyField(form.brokerage)) {

            return false;

        }

        if (!form.id && !isSymbolValidated) {

            return false;

        }

        return true;

    }, [form, isSymbolValidated, resolvedTransactionDate]);



    const columns = useMemo(() => [

        {
            accessorKey: 'transaction_date',
            header: 'Date',
            cell: ({ getValue }) => formatTransactionDateDisplay(getValue()),
        },

        {

            id: 'stock',

            header: 'Stock',

            accessorFn: (row) => row.stock?.symbol,

        },

        { accessorKey: 'type', header: 'Type' },

        {
            accessorKey: 'quantity',
            header: 'Qty',
            cell: ({ getValue }) => formatTableInteger(getValue()),
        },

        {
            accessorKey: 'price',
            header: 'Price',
            cell: ({ getValue }) => formatTableMoney2(getValue()),
        },

        {

            id: 'actions',

            header: 'Actions',

            enableSorting: false,

            enableHiding: false,

            cell: ({ row }) => (

                <>

                    <button

                        type="button"

                        className="btn btn-sm btn-outline-primary me-2"

                        onClick={() => editTx(row.original)}

                    >

                        Edit

                    </button>

                    <button

                        type="button"

                        className="btn btn-sm btn-outline-danger"

                        onClick={() => deleteTx(row.original.id)}

                    >

                        Delete

                    </button>

                </>

            ),

        },

    ], [editTx, deleteTx]);



    return (

        <div className="row g-3">

            <div className="col-12 col-lg-5">

                <div className="card" id="transaction-form-card">

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
                                            ariaLabel="Stock exchange"
                                            value={form.exchange}
                                            onChange={handleExchangeChange}
                                            options={[
                                                { value: 'NSE', label: 'NSE' },
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

                                                    exchange={form.exchange}

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

                                <label className="form-label" htmlFor="tx-brokerage">Brokerage</label>

                                <NumberInput
                                    id="tx-brokerage"
                                    min="0"
                                    step="0.05"
                                    placeholder="0.00"
                                    value={form.brokerage}
                                    onChange={(e) => setForm({ ...form, brokerage: e.target.value })}
                                    onBlur={(e) => setForm({
                                        ...form,
                                        brokerage: e.target.value === '' ? '0.00' : roundToTwoDecimals(e.target.value),
                                    })}
                                />

                            </div>



                            <div>

                                <label className="form-label" htmlFor="tx-date">Transaction date</label>

                                <input

                                    id="tx-date"

                                    className={`form-control${transactionDateIsFuture ? ' is-invalid' : ''}`}

                                    type="text"

                                    inputMode="text"

                                    autoComplete="off"

                                    placeholder="dd-mmm-yyyy"

                                    value={transactionDateInput}

                                    onChange={(e) => {

                                        const next = e.target.value;

                                        setTransactionDateInput(next);

                                        const iso = parseTransactionDateDisplay(next);

                                        if (iso) {

                                            setForm((prev) => ({ ...prev, transaction_date: iso }));

                                        }

                                    }}

                                    onFocus={() => {

                                        dateInputFocusedRef.current = true;

                                    }}

                                    onBlur={() => {

                                        dateInputFocusedRef.current = false;

                                        const iso = parseTransactionDateDisplay(transactionDateInput);

                                        if (iso) {

                                            setForm((prev) => ({ ...prev, transaction_date: iso }));

                                            setTransactionDateInput(formatTransactionDateDisplay(iso));

                                        } else {

                                            setTransactionDateInput(

                                                formatTransactionDateDisplay(form.transaction_date),

                                            );

                                        }

                                    }}

                                    required

                                    aria-invalid={transactionDateIsFuture}

                                    aria-describedby={transactionDateIsFuture ? 'tx-date-error' : undefined}

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



                            <button className="btn btn-primary" type="submit" disabled={!canSubmit}>

                                {form.id ? 'Update Transaction' : 'Save Transaction'}

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

            </div>

            <div className="col-12 col-lg-7">

                <DataTableCard

                    title="Transaction History"

                    columns={columns}

                    data={transactions}

                    storageKey="transactions"

                    emptyMessage="No transactions yet."

                />

            </div>

        </div>

    );

}


