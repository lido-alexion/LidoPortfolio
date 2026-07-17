import React, { useEffect, useMemo, useRef, useState } from 'react';
import api from '../api';
import { debounce } from '../utils/debounce';
import { stockExchangeLabel } from '../utils/exchangeDisplay';
import logger from '../services/logger';

const MIN_CHARS = 2;

export default function StockAutocomplete({
    value,
    onChange,
    onSelect,
    exchange = 'NSE',
    disabled = false,
    required = false,
    hideLabel = false,
    clearOnBlur = false,
    id = 'stock-symbol-input',
    placeholder = 'Search symbol or company (min 2 chars)',
}) {
    const [query, setQuery] = useState(value?.symbol || value || '');
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const pickedRef = useRef(false);
    const onChangeRef = useRef(onChange);

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    useEffect(() => {
        setQuery(value?.symbol || value || '');
    }, [value]);

    const fetchSuggestions = useMemo(
        () => debounce(async (term) => {
            const q = term.trim().toUpperCase();
            if (q.length < MIN_CHARS) {
                setSuggestions([]);
                setLoading(false);
                setOpen(false);
                return;
            }

            setLoading(true);
            try {
                const res = await api.get('/stocks/search', {
                    params: { q, exchange, limit: 20 },
                    skipErrorToast: true,
                });
                const items = res.data.data || [];
                setSuggestions(items);
                setOpen(items.length > 0);
            } catch (err) {
                logger.error('Stock search failed', {
                    category: 'API',
                    api: '/stocks/search',
                    message: err?.response?.data?.message,
                });
                setSuggestions([]);
                setOpen(false);
            } finally {
                setLoading(false);
            }
        }, 300),
        [exchange],
    );

    useEffect(() => () => fetchSuggestions.cancel(), [fetchSuggestions]);

    const clearTypedQuery = () => {
        fetchSuggestions.cancel();
        setQuery('');
        setSuggestions([]);
        setOpen(false);
        setLoading(false);
        onChangeRef.current?.('');
    };

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setOpen(false);
                if (clearOnBlur && !pickedRef.current) {
                    clearTypedQuery();
                }
                pickedRef.current = false;
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [clearOnBlur, fetchSuggestions]);

    const handleInput = (e) => {
        const next = e.target.value.toUpperCase();
        pickedRef.current = false;
        setQuery(next);
        onChange?.(next);
        setOpen(false);
        fetchSuggestions(next);
    };

    const pick = (stock) => {
        pickedRef.current = true;
        setQuery(stock.symbol);
        setOpen(false);
        setSuggestions([]);
        onSelect?.(stock);
    };

    const showSuggestions = open && !loading && suggestions.length > 0;

    return (
        <div className="position-relative" ref={containerRef}>
            {!hideLabel && <label className="form-label" htmlFor={id}>Stock</label>}
            <div className="lido-stock-autocomplete-input-wrap">
                <input
                    id={id}
                    className={`form-control${loading ? ' has-trailing-indicator' : ''}`}
                    value={query}
                    onChange={handleInput}
                    onFocus={() => {
                        if (query.length >= MIN_CHARS && suggestions.length > 0) {
                            setOpen(true);
                        }
                    }}
                    onBlur={() => {
                        if (!clearOnBlur) {
                            return;
                        }
                        // Defer so suggestion clicks can mark a pick first.
                        window.setTimeout(() => {
                            if (pickedRef.current) {
                                pickedRef.current = false;
                                return;
                            }
                            if (containerRef.current?.contains(document.activeElement)) {
                                return;
                            }
                            clearTypedQuery();
                        }, 120);
                    }}
                    disabled={disabled}
                    required={required}
                    placeholder={placeholder}
                    autoComplete="off"
                    aria-busy={loading}
                />
                {loading && (
                    <div className="lido-stock-autocomplete-indicator" aria-hidden="true">
                        <span
                            className="spinner-border spinner-border-sm"
                            role="status"
                            aria-label="Searching for stocks"
                        />
                    </div>
                )}
            </div>
            {showSuggestions && (
                <ul
                    className="list-group position-absolute w-100 shadow-sm lido-stock-autocomplete-list"
                >
                    {suggestions.map((stock) => (
                        <li key={stock.id}>
                            <button
                                type="button"
                                className="list-group-item list-group-item-action text-start"
                                onClick={() => pick(stock)}
                            >
                                <strong>{stock.symbol}</strong>
                                <span className="text-muted ms-2">{stockExchangeLabel(stock)}</span>
                                <div className="small text-muted">{stock.name}</div>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
