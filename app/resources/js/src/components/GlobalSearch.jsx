import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import api from '../api';
import { holdingsPricesPath } from '../navigation/routes';
import { normalizeGlobalSearchQuery, searchVisiblePages } from '../utils/globalSearch';

const MIN_STOCK_QUERY_LENGTH = 2;
const STOCK_DEBOUNCE_MS = 300;
const FOCUSABLE_SELECTOR = [
    'a[href]:not([disabled])',
    'button:not([disabled])',
    'input:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function useConstrainedWidth() {
    const [isConstrained, setIsConstrained] = useState(() => (
        typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(max-width: 1199.98px)').matches
    ));

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return undefined;
        const media = window.matchMedia('(max-width: 1199.98px)');
        const update = () => setIsConstrained(media.matches);
        update();
        media.addEventListener?.('change', update);
        return () => media.removeEventListener?.('change', update);
    }, []);

    return isConstrained;
}

function resultLabel(result) {
    return result.kind === 'stock'
        ? `${result.symbol}${result.name ? `, ${result.name}` : ''}${result.exchange ? `, ${result.exchange}` : ''}`
        : `${result.label}, ${result.secondary}`;
}

function SearchResults({ results, activeIndex, onHover, onSelect }) {
    const pages = results.filter((result) => result.kind === 'page');
    const stocks = results.filter((result) => result.kind === 'stock');
    let index = 0;

    const renderGroup = (title, items) => {
        if (!items.length) return null;
        const group = (
            <section className="lido-global-search-group" key={title}>
                <h2>{title}</h2>
                <ul>
                    {items.map((result) => {
                        const resultIndex = index;
                        index += 1;
                        return (
                            <li key={`${result.kind}-${result.sourceId}`}>
                                <Link
                                    to={result.destination}
                                    className={`lido-global-search-result${activeIndex === resultIndex ? ' is-active' : ''}`}
                                    aria-label={resultLabel(result)}
                                    onMouseEnter={() => onHover(resultIndex)}
                                    onClick={() => {
                                        onSelect(result);
                                    }}
                                >
                                    <span className="lido-global-search-result-label">
                                        {result.kind === 'stock' ? result.symbol : result.label}
                                    </span>
                                    <span className="lido-global-search-result-secondary">
                                        {result.kind === 'stock'
                                            ? `${result.name || 'Stock'}${result.exchange ? ` · ${result.exchange}` : ''}`
                                            : result.secondary}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </section>
        );
        return group;
    };

    return (
        <div className="lido-global-search-results" aria-label="Search results">
            {renderGroup('Pages', pages)}
            {renderGroup('Stocks', stocks)}
        </div>
    );
}

function SearchSurface({ mobile, inputRef, surfaceRef, query, setQuery, results, activeIndex, onKeyDown, onHover, onSelect, onClose, stockLoading, stockError }) {
    const hasQuery = Boolean(normalizeGlobalSearchQuery(query));
    const hasResults = results.length > 0;
    const status = stockLoading
        ? 'Searching stocks…'
        : stockError
            ? 'Stock search is temporarily unavailable. Page results remain available.'
            : hasQuery && !hasResults
                ? 'No matches'
                : '';

    return (
        <div
            ref={surfaceRef}
            className={`lido-global-search-surface${mobile ? ' lido-global-search-surface--mobile' : ' lido-global-search-surface--desktop'}`}
            {...(mobile ? {
                role: 'dialog',
                'aria-modal': 'true',
                'aria-labelledby': 'lido-global-search-title',
            } : {})}
        >
            {mobile && (
                <header className="lido-global-search-mobile-header">
                    <h2 id="lido-global-search-title">Search StoX</h2>
                    <button type="button" className="lido-icon-action" aria-label="Close search" title="Close search" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>
            )}
            <label className="visually-hidden" htmlFor="lido-global-search-input">Search pages or stocks</label>
            <input
                ref={inputRef}
                id="lido-global-search-input"
                type="search"
                className="form-control lido-global-search-input"
                placeholder="Search pages or stocks"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={onKeyDown}
                autoComplete="off"
                aria-describedby="lido-global-search-status"
            />
            <div id="lido-global-search-status" className="lido-global-search-status" aria-live="polite">
                {hasQuery && stockLoading ? 'Searching stocks…' : null}
                {hasQuery && !stockLoading && stockError ? 'Stock search is temporarily unavailable.' : null}
                {hasQuery && !stockLoading && !stockError && !hasResults ? 'No matches' : null}
                {!hasQuery ? 'Search pages or stocks' : null}
            </div>
            {hasResults ? (
                <SearchResults results={results} activeIndex={activeIndex} onHover={onHover} onSelect={onSelect} />
            ) : (
                <div className="lido-global-search-empty">{status || 'Search pages or stocks'}</div>
            )}
        </div>
    );
}

function GlobalSearchFeature({ user }) {
    const navigate = useNavigate();
    const location = useLocation();
    const isConstrained = useConstrainedWidth();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [stockResults, setStockResults] = useState([]);
    const [stockLoading, setStockLoading] = useState(false);
    const [stockError, setStockError] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const triggerRef = useRef(null);
    const inputRef = useRef(null);
    const surfaceRef = useRef(null);
    const openRef = useRef(false);
    const requestGenerationRef = useRef(0);
    const lastPathRef = useRef(location.pathname);

    const pageResults = useMemo(() => searchVisiblePages(query, user), [query, user]);
    const results = useMemo(() => [
        ...pageResults,
        ...stockResults.map((stock) => ({
            kind: 'stock',
            symbol: stock.symbol,
            name: stock.name,
            exchange: stock.exchange,
            sourceId: stock.id,
            destination: holdingsPricesPath(stock.id),
        })),
    ], [pageResults, stockResults]);

    const clearTransientState = useCallback(() => {
        requestGenerationRef.current += 1;
        setQuery('');
        setStockResults([]);
        setStockLoading(false);
        setStockError(false);
        setActiveIndex(-1);
    }, []);

    const closeSearch = useCallback((restoreFocus = true) => {
        openRef.current = false;
        setOpen(false);
        clearTransientState();
        if (restoreFocus) {
            window.setTimeout(() => triggerRef.current?.focus(), 0);
        }
    }, [clearTransientState]);

    const openSearch = useCallback(() => {
        window.dispatchEvent(new CustomEvent('lido-global-search-open', { detail: { modal: isConstrained } }));
        openRef.current = true;
        setOpen(true);
    }, [isConstrained]);

    useEffect(() => {
        if (!open) return undefined;
        inputRef.current?.focus();
        const handleKeyDown = (event) => {
            if (!isConstrained || event.key !== 'Tab') return;
            const surface = surfaceRef.current;
            if (!surface) return;
            const focusables = Array.from(surface.querySelectorAll(FOCUSABLE_SELECTOR));
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey && (document.activeElement === first || !surface.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !surface.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [isConstrained, open]);

    useEffect(() => {
        const closeForMobileUtility = (event) => {
            if (event.detail?.modal && openRef.current) closeSearch(false);
        };
        window.addEventListener('lido-mobile-utility-open', closeForMobileUtility);
        return () => window.removeEventListener('lido-mobile-utility-open', closeForMobileUtility);
    }, [closeSearch]);

    useEffect(() => {
        if (lastPathRef.current !== location.pathname) {
            lastPathRef.current = location.pathname;
            if (openRef.current) closeSearch(false);
        }
    }, [closeSearch, location.pathname]);

    useEffect(() => {
        const normalizedQuery = normalizeGlobalSearchQuery(query);
        const generation = ++requestGenerationRef.current;
        setStockResults([]);
        setStockError(false);
        setActiveIndex(-1);

        if (!open || normalizedQuery.length < MIN_STOCK_QUERY_LENGTH) {
            setStockLoading(false);
            return undefined;
        }

        setStockLoading(true);
        const timer = window.setTimeout(async () => {
            try {
                const response = await api.get('/stocks/search', {
                    params: { q: normalizedQuery, limit: 20 },
                    skipErrorToast: true,
                });
                if (generation !== requestGenerationRef.current || !openRef.current) return;
                setStockResults(Array.isArray(response.data?.data) ? response.data.data : []);
            } catch {
                if (generation !== requestGenerationRef.current || !openRef.current) return;
                setStockError(true);
            } finally {
                if (generation === requestGenerationRef.current && openRef.current) setStockLoading(false);
            }
        }, STOCK_DEBOUNCE_MS);
        return () => window.clearTimeout(timer);
    }, [open, query]);

    useEffect(() => {
        if (!open) return undefined;
        const handleClickAway = (event) => {
            if (surfaceRef.current?.contains(event.target) || triggerRef.current?.contains(event.target)) return;
            closeSearch(true);
        };
        document.addEventListener('mousedown', handleClickAway);
        return () => document.removeEventListener('mousedown', handleClickAway);
    }, [closeSearch, open]);

    const selectResult = (result) => {
        closeSearch(false);
        navigate(result.destination);
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeSearch(true);
            return;
        }
        if (!results.length) return;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((current) => (current + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((current) => (current <= 0 ? results.length - 1 : current - 1));
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            selectResult(results[activeIndex]);
        }
    };

    return (
        <div className="lido-global-search">
            <button
                ref={triggerRef}
                type="button"
                className="lido-global-search-trigger lido-icon-action"
                aria-label="Open global search"
                title="Search pages or stocks"
                aria-expanded={open}
                aria-haspopup={isConstrained ? 'dialog' : undefined}
                onClick={open ? () => closeSearch(true) : openSearch}
            >
                <Search size={18} aria-hidden="true" />
            </button>
            {open && (
                <>
                    {isConstrained && <div className="lido-global-search-backdrop" onMouseDown={() => closeSearch(true)} aria-hidden="true" />}
                    <SearchSurface
                        mobile={isConstrained}
                        inputRef={inputRef}
                        surfaceRef={surfaceRef}
                        query={query}
                        setQuery={setQuery}
                        results={results}
                        activeIndex={activeIndex}
                        onKeyDown={onKeyDown}
                        onHover={setActiveIndex}
                        onSelect={selectResult}
                        onClose={() => closeSearch(true)}
                        stockLoading={stockLoading}
                        stockError={stockError}
                    />
                </>
            )}
        </div>
    );
}

export default function GlobalSearch({ user }) {
    if (!user || user.is_admin) return null;
    return <GlobalSearchFeature user={user} />;
}
