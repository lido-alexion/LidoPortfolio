import React, { useCallback, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';

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
import { stockExchangeLabel } from '../utils/exchangeDisplay';

import FeeBreakdownHint from '../components/FeeBreakdownHint';
import BulkTransactionImport from '../components/BulkTransactionImport';
import TransactionDateInput from '../components/TransactionDateInput';
import PendingExecutionPanel from '../components/PendingExecutionPanel';
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
import { ownerLabelFromKey } from '../utils/sellTransactionPrefill';
import PortfolioContext from '../context/PortfolioContext';
import AuthContext from '../context/AuthContext';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';
import { PORTFOLIO_CHANGED } from '../utils/portfolioEvents';



const emptyForm = () => ({
    id: null,
    stock_id: '',
    symbol: '',
    name: '',
    exchange: 'NSE',
    type: 'buy',
    quantity: '',
    price: '',
    total_invested: '',
    transaction_date: getLocalTodayDateString(),
    notes: '',
    recommendation_id: null,
    owner_key: '',
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

    return true;

}



function isValidPrice(value) {

    return isValidMoneyField(value) && Number(value) > 0;

}

function calculatedDecimal(value) {

    if (!Number.isFinite(value)) {

        return '';

    }

    return value.toFixed(8).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');

}

function calculatedTotal(quantity, price) {

    const qty = Number(quantity);
    const unitPrice = Number(price);

    return Number.isFinite(qty) && qty > 0 && Number.isFinite(unitPrice) && unitPrice > 0
        ? calculatedDecimal(qty * unitPrice)
        : '';

}



export default function TransactionsPage() {
    const location = useLocation();
    const navigate = useNavigate();
    const portfolioContext = useContext(PortfolioContext);
    const authContext = useContext(AuthContext);
    const userId = authContext?.user?.id == null ? null : String(authContext.user.id);
    const portfolioId = portfolioContext?.activePortfolio?.id == null
        ? null : String(portfolioContext.activePortfolio.id);
    const scope = userId && portfolioId ? `${userId}:${portfolioId}` : null;
    const [portfolioEventRevision, setPortfolioEventRevision] = useState(0);
    const renderedOwnershipKey = scope ? `${scope}:${portfolioEventRevision}` : null;
    const ownershipRef = useRef({ key: null, mounted: false });
    const operationRef = useRef(0);
    const transactionReadRef = useRef(0);
    const settingsReadRef = useRef(0);
    const lotsReadRef = useRef(0);
    const ownershipIsCurrent = useCallback(() => (
        Boolean(renderedOwnershipKey)
        && ownershipRef.current.mounted
        && ownershipRef.current.key === renderedOwnershipKey
        && String(getActivePortfolioId()) === portfolioId
        && String(authContext?.user?.id ?? '') === userId
    ), [renderedOwnershipKey, portfolioId, userId, authContext?.user?.id]);
    const [transactions, setTransactionsRaw] = useState([]);
    const setTransactions = useCallback((value) => { if (ownershipIsCurrent()) setTransactionsRaw(value); }, [ownershipIsCurrent]);

    const [loading, setLoadingRaw] = useState(true);
    const setLoading = useCallback((value) => { if (ownershipIsCurrent()) setLoadingRaw(value); }, [ownershipIsCurrent]);

    const [form, setFormState] = useState(emptyForm());
    const setForm = useCallback((value) => { if (ownershipIsCurrent()) setFormState(value); }, [ownershipIsCurrent]);

    const [sellLots, setSellLotsRaw] = useState([]);
    const setSellLots = useCallback((value) => { if (ownershipIsCurrent()) setSellLotsRaw(value); }, [ownershipIsCurrent]);

    const [selectedStock, setSelectedStockRaw] = useState(null);
    const setSelectedStock = useCallback((value) => { if (ownershipIsCurrent()) setSelectedStockRaw(value); }, [ownershipIsCurrent]);

    const [symbolValidation, setSymbolValidationRaw] = useState(null);
    const setSymbolValidation = useCallback((value) => { if (ownershipIsCurrent()) setSymbolValidationRaw(value); }, [ownershipIsCurrent]);

    const [validatingSymbol, setValidatingSymbolRaw] = useState(false);
    const setValidatingSymbol = useCallback((value) => { if (ownershipIsCurrent()) setValidatingSymbolRaw(value); }, [ownershipIsCurrent]);

    const [submitting, setSubmittingRaw] = useState(false);
    const setSubmitting = useCallback((value) => { if (ownershipIsCurrent()) setSubmittingRaw(value); }, [ownershipIsCurrent]);
    const [transactionSaveError, setTransactionSaveErrorRaw] = useState('');
    const setTransactionSaveError = useCallback((value) => { if (ownershipIsCurrent()) setTransactionSaveErrorRaw(value); }, [ownershipIsCurrent]);

    const validationTokenRef = useRef(0);

    const dateInputFocusedRef = useRef(false);

    const [transactionDateInput, setTransactionDateInputRaw] = useState(() => (
        formatTransactionDateDisplay(getLocalTodayDateString())
    ));
    const setTransactionDateInput = useCallback((value) => { if (ownershipIsCurrent()) setTransactionDateInputRaw(value); }, [ownershipIsCurrent]);

    const [feeComponents, setFeeComponentsRaw] = useState(DEFAULT_FEE_COMPONENTS);
    const setFeeComponents = useCallback((value) => { if (ownershipIsCurrent()) setFeeComponentsRaw(value); }, [ownershipIsCurrent]);

    const [stockSearch, setStockSearchRaw] = useState('');
    const setStockSearch = useCallback((value) => { if (ownershipIsCurrent()) setStockSearchRaw(value); }, [ownershipIsCurrent]);

    useLayoutEffect(() => {
        ownershipRef.current.mounted = true;
        return () => {
            ownershipRef.current.mounted = false;
            ownershipRef.current.key = null;
            operationRef.current += 1;
            validationTokenRef.current += 1;
            transactionReadRef.current += 1;
            settingsReadRef.current += 1;
            lotsReadRef.current += 1;
        };
    }, []);

    useLayoutEffect(() => {
        const nextKey = scope
            && getActivePortfolioId() === portfolioId
            ? renderedOwnershipKey : null;
        const previousKey = ownershipRef.current.key;
        ownershipRef.current.key = nextKey;
        if (previousKey !== nextKey) {
            operationRef.current += 1;
            validationTokenRef.current += 1;
            transactionReadRef.current += 1;
            settingsReadRef.current += 1;
            lotsReadRef.current += 1;
            setTransactionsRaw([]);
            setLoadingRaw(Boolean(nextKey));
            setSellLotsRaw([]);
            setFormState(emptyForm());
            setSelectedStockRaw(null);
            setSymbolValidationRaw(null);
            setValidatingSymbolRaw(false);
            setSubmittingRaw(false);
            setTransactionSaveErrorRaw('');
            setStockSearchRaw('');
            setFeeComponentsRaw(DEFAULT_FEE_COMPONENTS);
            dateInputFocusedRef.current = false;
        }
    }, [scope, portfolioId, renderedOwnershipKey]);

    useLayoutEffect(() => {
        const onPortfolioChanged = () => {
            ownershipRef.current.key = null;
            operationRef.current += 1;
            validationTokenRef.current += 1;
            transactionReadRef.current += 1;
            settingsReadRef.current += 1;
            lotsReadRef.current += 1;
            setPortfolioEventRevision((revision) => revision + 1);
            setTransactionsRaw([]);
            setLoadingRaw(true);
            setSellLotsRaw([]);
            setFormState(emptyForm());
            setSelectedStockRaw(null);
            setSymbolValidationRaw(null);
            setValidatingSymbolRaw(false);
            setSubmittingRaw(false);
            setTransactionSaveErrorRaw('');
            setStockSearchRaw('');
            dateInputFocusedRef.current = false;
        };
        window.addEventListener(PORTFOLIO_CHANGED, onPortfolioChanged);
        return () => window.removeEventListener(PORTFOLIO_CHANGED, onPortfolioChanged);
    }, []);

    const [entryMode, setEntryModeRaw] = useState('single');
    const setEntryMode = useCallback((value) => { if (ownershipIsCurrent()) setEntryModeRaw(value); }, [ownershipIsCurrent]);
    const pageTab = location.pathname.startsWith('/transactions/pending') ? 'pending' : 'history';

    const setPageTab = useCallback((tab) => {
        navigate(tab === 'pending' ? '/transactions/pending' : '/transactions');
    }, [navigate]);



    const load = useCallback(async () => {
        if (!scope || !ownershipIsCurrent()) return;
        const owns = ownershipIsCurrent;
        const request = ++transactionReadRef.current;
        setLoading(true);
        try {
            const txRes = await api.get('/transactions', { params: { scope: 'open', per_page: 500 } });
            if (request === transactionReadRef.current && owns()) setTransactions(txRes.data.data || []);
        } catch {
            // Failed reads keep the existing rows; a later event or manual reload can recover.
        } finally {
            if (request === transactionReadRef.current && owns()) setLoading(false);
        }
    }, [ownershipIsCurrent, scope]);

    const loadFeeSettings = useCallback(async () => {
        if (!scope) return;
        const owns = ownershipIsCurrent;
        const request = ++settingsReadRef.current;
        try {
            const res = await api.get('/settings');
            if (request === settingsReadRef.current && owns()) setFeeComponents(normalizeFeeComponents(res.data.data?.fee_components));
        } catch {
            // Keep the last confirmed fee settings when this read fails.
        }
    }, [ownershipIsCurrent, scope]);



    useEffect(() => {
        if (!scope || !ownershipIsCurrent()) return;
        load();
        loadFeeSettings();
    }, [load, loadFeeSettings, renderedOwnershipKey, scope]);

    useEffect(() => {
        if (!scope || !ownershipIsCurrent()) return;
        const prefill = location.state?.sellPrefill;
        if (!prefill?.stock) {
            return;
        }

        const stock = prefill.stock;
        const exchange = stock.exchange || 'NSE';

        operationRef.current += 1;
        validationTokenRef.current += 1;
        setSubmitting(false);
        setValidatingSymbol(false);
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
            price: prefill.price != null && prefill.price > 0 ? String(prefill.price) : '',
            total_invested: calculatedTotal(prefill.quantity, prefill.price),
            transaction_date: getLocalTodayDateString(),
            owner_key: prefill.owner_key || '',
        });

        navigate('/transactions', { replace: true, state: {} });

        const owns = ownershipIsCurrent;
        requestAnimationFrame(() => {
            if (!owns()) return;
            document.getElementById('transaction-form-card')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }, [location.state, navigate, scope, ownershipIsCurrent]);

    const sellStockId = Number(form.stock_id || selectedStock?.id || 0);

    useEffect(() => {
        if (!ownershipIsCurrent()) return undefined;
        if (form.type !== 'sell' || !sellStockId) {
            setSellLots([]);
            return undefined;
        }
        const owns = ownershipIsCurrent;
        const request = ++lotsReadRef.current;
        let cancelled = false;
        api.get('/holdings')
            .then((res) => {
                if (cancelled || request !== lotsReadRef.current || !owns()) {
                    return;
                }
                const lots = (res.data?.data || []).filter(
                    (h) => Number(h.stock_id) === sellStockId && Number(h.quantity) > 0,
                );
                setSellLots(lots);
            })
            .catch(() => {
                if (!cancelled && request === lotsReadRef.current && owns()) {
                    setSellLots([]);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [form.type, sellStockId, ownershipIsCurrent]);

    const ownerOptions = useMemo(() => {
        const map = new Map();
        sellLots.forEach((h) => {
            const key = h.owner_key || 'unmanaged';
            map.set(key, {
                owner_key: key,
                quantity: Number(h.quantity) || 0,
            });
        });
        if (form.owner_key && !map.has(form.owner_key)) {
            map.set(form.owner_key, { owner_key: form.owner_key, quantity: 0 });
        }
        return [...map.values()];
    }, [sellLots, form.owner_key]);

    useEffect(() => {
        if (form.type !== 'sell' || form.recommendation_id || form.owner_key) {
            return;
        }
        if (ownerOptions.length === 1) {
            setForm((prev) => (prev.owner_key ? prev : { ...prev, owner_key: ownerOptions[0].owner_key }));
        }
    }, [form.type, form.recommendation_id, form.owner_key, ownerOptions]);

    useEffect(() => {
        if (!ownershipIsCurrent() || !location.state?.focusAddForm) {
            return;
        }
        setEntryMode('single');
        navigate('/transactions', { replace: true, state: {} });
        const owns = ownershipIsCurrent;
        requestAnimationFrame(() => {
            if (!owns()) return;
            const card = document.getElementById('transaction-form-card');
            card?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            card?.querySelector('input, select, textarea, button')?.focus?.();
        });
    }, [location.state, navigate, ownershipIsCurrent]);

    useEffect(() => {
        if (!ownershipIsCurrent()) return;
        const search = location.state?.transactionSearch;
        if (typeof search !== 'string' || !search.trim()) {
            return;
        }

        setStockSearch(search.trim());
        navigate('/transactions', { replace: true, state: {} });

        const owns = ownershipIsCurrent;
        requestAnimationFrame(() => {
            if (!owns()) return;
            document.getElementById('active-transactions-table')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }, [location.state, navigate, ownershipIsCurrent]);

    useEffect(() => {

        if (dateInputFocusedRef.current) {

            return;

        }
        if (!ownershipIsCurrent()) return;

        setTransactionDateInput(formatTransactionDateDisplay(form.transaction_date));

    }, [form.transaction_date, ownershipIsCurrent]);



    useEffect(() => {

        if (!ownershipIsCurrent()) return;

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

    }, [form.symbol, form.id, symbolValidation, ownershipIsCurrent]);



    const applyValidatedStock = useCallback((stock, source, cached = false) => {
        if (!ownershipIsCurrent()) return;

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

    }, [ownershipIsCurrent, setForm]);



    const createTx = async (e) => {

        e.preventDefault();
        const owns = ownershipIsCurrent;
        if (!owns()) return;
        const operation = ++operationRef.current;
        const current = () => operationRef.current === operation && owns();

        const txDate = parseTransactionDateDisplay(transactionDateInput);

        if (!isValidTransactionDate(txDate)) {
            showToast('Enter a valid transaction date (dd-mmm-yyyy) that is not in the future', 'danger');
            return;
        }

        const payload = { ...buildPayload(), transaction_date: txDate };

        if (!form.id && form.type === 'sell' && !form.recommendation_id && ownerOptions.length > 1 && !form.owner_key) {
            showToast('This sell could affect more than one owner. Choose the Strategy or unmanaged lot.', 'danger');
            return;
        }



        if (!form.id && !isSymbolValidated) {

            showToast('Validate the stock symbol before saving', 'danger');

            return;

        }

        setTransactionSaveError('');
        setSubmitting(true);

        try {

            if (!current()) return;

            if (form.id) {

                await api.put(`/transactions/${form.id}`, payload);

                if (!current()) return;

                showToast('Transaction updated');

                setForm(emptyForm());

                setSelectedStock(null);

                setSymbolValidation(null);

            } else {
                const { data } = await api.post('/transactions', payload);
                if (!current()) return;
                showToast(data?.message || 'Transaction saved', 'success');
                setForm(emptyForm());
                setSelectedStock(null);
                setSymbolValidation(null);
                setTransactionDateInput(formatTransactionDateDisplay(getLocalTodayDateString()));
            }

            if (!current()) return;
            await load();

            if (current()) notifyPortfolioDashboardRefresh();

        } catch {
            if (current()) setTransactionSaveError('Transaction save could not be confirmed. Review existing evidence before retrying.');
        } finally {

            if (current()) setSubmitting(false);

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

        if (form.recommendation_id && !form.id) {
            base.recommendation_id = form.recommendation_id;
            base.source = 'recommendation';
        }

        if (form.type === 'sell' && form.owner_key) {
            base.owner_key = form.owner_key;
        }

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
        if (!ownershipIsCurrent()) return;
        operationRef.current += 1;
        validationTokenRef.current += 1;
        setSubmitting(false);
        setValidatingSymbol(false);
        setTransactionSaveError('');

        setForm({

            id: tx.id,

            stock_id: String(tx.stock_id),

            symbol: tx.stock?.symbol || '',

            name: tx.stock?.name || '',

            exchange: tx.stock?.exchange || 'NSE',

            type: tx.type,

            quantity: Number(tx.quantity),

            price: String(tx.price),
            total_invested: calculatedTotal(tx.quantity, tx.price),

            transaction_date: tx.transaction_date,

            notes: tx.notes || '',
            recommendation_id: null,
            owner_key: tx.owner_key || '',
        });

        setSelectedStock(tx.stock || null);

        setSymbolValidation(null);

        setTransactionDateInput(formatTransactionDateDisplay(tx.transaction_date));

    }, [ownershipIsCurrent]);



    useEffect(() => {
        if (!scope) return;
        const tx = location.state?.editTransaction;
        if (!tx) {
            return;
        }
        setEntryMode('single');
        if (!ownershipIsCurrent()) return;
        editTx(tx);
        navigate('/transactions', { replace: true, state: {} });
        const owns = ownershipIsCurrent;
        requestAnimationFrame(() => {
            if (!owns()) return;
            document.getElementById('transaction-form-card')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }, [location.state, editTx, navigate, scope, ownershipIsCurrent]);

    const applyExecuteRecommendation = useCallback((prefill) => {
        if (!ownershipIsCurrent()) return;
        if (!prefill?.stock_id && !prefill?.symbol) {
            return;
        }
        operationRef.current += 1;
        validationTokenRef.current += 1;
        setSubmitting(false);
        setValidatingSymbol(false);
        setEntryMode('single');
        const stock = {
            id: prefill.stock_id,
            symbol: prefill.symbol,
            name: prefill.name || '',
            exchange: prefill.exchange || 'NSE',
        };
        setSelectedStock(stock);
        setForm({
            ...emptyForm(),
            stock_id: String(prefill.stock_id || ''),
            symbol: prefill.symbol || '',
            name: prefill.name || '',
            exchange: prefill.exchange || 'NSE',
            type: prefill.type === 'sell' ? 'sell' : 'buy',
            quantity: prefill.quantity !== '' && prefill.quantity != null ? Number(prefill.quantity) : '',
            price: prefill.price != null && prefill.price !== '' ? String(prefill.price) : '',
            total_invested: calculatedTotal(prefill.quantity, prefill.price),
            notes: prefill.notes || '',
            recommendation_id: prefill.recommendation_id || null,
            owner_key: '',
        });
        setSymbolValidation({ valid: true, stock, source: 'recommendation' });
        const owns = ownershipIsCurrent;
        requestAnimationFrame(() => {
            if (!owns()) return;
            document.getElementById('transaction-form-card')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }, [ownershipIsCurrent, setForm]);

    useEffect(() => {
        if (!scope || !ownershipIsCurrent()) return;
        const prefill = location.state?.executeRecommendation;
        if (!prefill) {
            return;
        }
        applyExecuteRecommendation(prefill);
        navigate('/transactions', { replace: true, state: {} });
    }, [location.state, applyExecuteRecommendation, navigate, scope, ownershipIsCurrent]);



    const deleteTx = useCallback(async (id) => {
        const owns = ownershipIsCurrent;
        if (!owns()) return;
        if (!window.confirm('Delete this transaction? If it came from a recommendation, that item returns to Pending Execution.')) {
            return;
        }
        if (!owns()) return;
        try {
            const { data } = await api.delete(`/transactions/${id}`);
            if (!owns()) return;
            const msg = data?.message || 'Transaction deleted';
            showToast(msg, data?.tos?.recommendation_reopened ? 'success' : undefined);
            await load();
            if (owns()) notifyPortfolioDashboardRefresh();
        } catch (e) {
            if (owns()) showToast(e?.response?.data?.message || e?.response?.data?.error?.message || e.message || 'Delete failed', 'danger');
        }
    }, [load, ownershipIsCurrent]);



    const handleStockSelect = (stock) => {
        if (!ownershipIsCurrent()) return;
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
        if (!ownershipIsCurrent()) return;
        if (symbol.trim().toUpperCase() !== form.symbol.trim().toUpperCase()) {
            validationTokenRef.current += 1;
            setValidatingSymbol(false);
        }

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
        if (!ownershipIsCurrent()) return;

        setForm((prev) => ({

            ...prev,

            exchange,

        }));

    };



    const validateSymbol = async () => {

        const owns = ownershipIsCurrent;
        if (!owns()) return;

        const symbol = form.symbol.trim().toUpperCase();

        if (symbol.length < 2) {

            showToast('Enter at least 2 characters for the symbol', 'danger');

            return;

        }



        const token = ++validationTokenRef.current;
        const currentValidation = () => token === validationTokenRef.current && owns();

        setValidatingSymbol(true);

        setSymbolValidation(null);



        try {

            const searchRes = await api.get('/stocks/search', {

                params: { q: symbol, limit: 20 },

                skipErrorToast: true,

            });

            if (!currentValidation()) {

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

                if (!currentValidation()) return;

                const cached = getCachedStockValidation(exchange, symbol);

                if (cached?.valid && cached.stock) {

                    if (!currentValidation()) return;

                    applyValidatedStock(cached.stock, cached.source || 'local', true);

                    return;

                }

                try {

                    if (!currentValidation()) return;

                    const res = await api.post('/stocks/validate', {

                        symbol,

                        exchange,

                        check_only: true,

                    });

                    if (!currentValidation()) {

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

                    if (!currentValidation()) return;

                    lastError = err;

                }

            }

            if (!currentValidation()) {

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

            if (!currentValidation()) {

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

            if (currentValidation()) {

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
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h1 className="h3 mb-0">Transactions</h1>
                    <SegmentToggle
                        ariaLabel="Transactions page tabs"
                        className="lido-segment-toggle--page-tabs"
                        value={pageTab}
                        onChange={setPageTab}
                        options={[
                            { value: 'pending', label: 'Pending Execution' },
                            { value: 'history', label: 'Transaction History' },
                        ]}
                    />
                </div>
            </div>

            {pageTab === 'pending' ? (
                <div className="col-12">
                    <PendingExecutionPanel />
                </div>
            ) : (
            <>

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
                {form.recommendation_id ? (
                    <p className="small text-muted mt-2 mb-0">
                        Recording actual broker fill for recommendation #
                        {form.recommendation_id}
                        . Adjust quantity, price, fees, and date to match what happened.
                    </p>
                ) : null}
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

                                    <label className="form-label" htmlFor="tx-stock-display">Stock</label>

                                    <input

                                        id="tx-stock-display"

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
                                            onChange={(type) => setForm({
                                                ...form,
                                                type,
                                                owner_key: type === 'sell' ? form.owner_key : '',
                                            })}
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
                                        onChange={(type) => setForm({
                                            ...form,
                                            type,
                                            owner_key: type === 'sell' ? form.owner_key : '',
                                        })}
                                        options={[
                                            { value: 'buy', label: 'Buy' },
                                            { value: 'sell', label: 'Sell' },
                                        ]}
                                    />
                                </div>
                            )}

                            {form.type === 'sell' && !form.recommendation_id && ownerOptions.length > 1 ? (
                                <div>
                                    <label className="form-label" htmlFor="tx-owner-key">Owner</label>
                                    <select
                                        id="tx-owner-key"
                                        className="form-select"
                                        value={form.owner_key || ''}
                                        onChange={(e) => setForm({ ...form, owner_key: e.target.value })}
                                        required
                                    >
                                        <option value="">Select Strategy or unmanaged lot</option>
                                        {ownerOptions.map((lot) => (
                                            <option key={lot.owner_key} value={lot.owner_key}>
                                                {ownerLabelFromKey(lot.owner_key)}
                                                {lot.quantity > 0 ? ` (${lot.quantity})` : ''}
                                            </option>
                                        ))}
                                    </select>
                                    <div className="form-text">
                                        More than one owner holds this stock. The sell is attributed only to the owner you select.
                                    </div>
                                </div>
                            ) : null}

                            <div>
                                <label className="form-label" htmlFor="tx-quantity">Quantity</label>
                                <NumberInput
                                    id="tx-quantity"
                                    min="1"
                                    step="1"
                                    allowDecimals={false}
                                    placeholder="e.g. 10"
                                    value={form.quantity}
                                    onChange={(e) => setForm((prev) => {
                                        const quantity = e.target.value === '' ? '' : parseInt(e.target.value, 10);
                                        return { ...prev, quantity, total_invested: calculatedTotal(quantity, prev.price) };
                                    })}
                                    required
                                />

                            </div>



                            <div>

                                <label className="form-label" htmlFor="tx-price">Price</label>

                                <NumberInput
                                    id="tx-price"
                                    min="0.0001"
                                    step="0.0001"
                                    preserveTypedPrecision
                                    placeholder="0.00"
                                    value={form.price}
                                    onChange={(e) => setForm((prev) => ({ ...prev, price: e.target.value, total_invested: calculatedTotal(prev.quantity, e.target.value) }))}
                                    required
                                />

                            </div>

                            <div>

                                <label className="form-label" htmlFor="tx-total-invested">Total invested (₹)</label>

                                <NumberInput
                                    id="tx-total-invested"
                                    min="0.0001"
                                    step="0.0001"
                                    preserveTypedPrecision
                                    placeholder="0.00"
                                    value={form.total_invested}
                                    onChange={(e) => setForm((prev) => {
                                        const totalInvested = e.target.value;
                                        const quantity = Number(prev.quantity);
                                        const total = Number(totalInvested);
                                        return {
                                            ...prev,
                                            total_invested: totalInvested,
                                            price: Number.isFinite(quantity) && quantity > 0 && Number.isFinite(total) && total > 0
                                                ? calculatedDecimal(total / quantity)
                                                : prev.price,
                                        };
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
                                    onFocus={() => { if (ownershipIsCurrent()) dateInputFocusedRef.current = true; }}
                                    onBlur={() => { if (ownershipIsCurrent()) dateInputFocusedRef.current = false; }}
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



                            {transactionSaveError && <div className="alert alert-danger py-2" role="alert">{transactionSaveError}</div>}
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
                                        if (!ownershipIsCurrent()) return;
                                        operationRef.current += 1;
                                        validationTokenRef.current += 1;
                                        setSubmitting(false);
                                        setValidatingSymbol(false);
                                        setTransactionSaveError('');
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

            </>
            )}

        </div>

    );

}
