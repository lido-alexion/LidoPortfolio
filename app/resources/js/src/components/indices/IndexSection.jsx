import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api';
import PriceVolumeChart from '../charts/PriceVolumeChart';
import { DataTableCard } from '../DataTable';
import IndiaVixAlertSettings from './IndiaVixAlertSettings';
import { formatSignedPercent2, formatTableMoney2 } from '../../utils/tableFormat';
import { formatTransactionDateDisplay } from '../../utils/transactionDate';

function pctClass(value) {
    if (value == null || Number.isNaN(Number(value))) {
        return 'text-muted';
    }
    return Number(value) >= 0 ? 'text-success' : 'text-danger';
}

function formatPctChip(value) {
    if (value == null || Number.isNaN(Number(value))) {
        return '—';
    }
    return formatSignedPercent2(value);
}

function IndexChangeChips({ changePercent }) {
    const periods = [
        ['1d', '1D'],
        ['15d', '15D'],
        ['1m', '1M'],
        ['3m', '3M'],
        ['6m', '6M'],
        ['1y', '1Y'],
    ];
    return (
        <div className="d-flex flex-wrap gap-2">
            {periods.map(([key, label]) => (
                <span key={key} className="indices-change-chip">
                    <span className="indices-change-chip-label">{label}</span>
                    <span className={`indices-change-chip-value ${pctClass(changePercent?.[key])}`}>
                        {formatPctChip(changePercent?.[key])}
                    </span>
                </span>
            ))}
        </div>
    );
}

export default function IndexSection({ index, panelId, expanded, onToggle }) {
    const [priceRows, setPriceRows] = useState([]);
    const [priceLoading, setPriceLoading] = useState(false);
    const [priceLoaded, setPriceLoaded] = useState(false);
    const [constituents, setConstituents] = useState([]);
    const [constituentsLoading, setConstituentsLoading] = useState(false);
    const [constituentsLoaded, setConstituentsLoaded] = useState(false);
    const [constituentSearch, setConstituentSearch] = useState('');
    const [constituentsMessage, setConstituentsMessage] = useState('');

    useEffect(() => {
        if (!expanded || priceLoaded || !index.stock_id) {
            return;
        }
        let cancelled = false;
        setPriceLoading(true);
        api.get(`/stocks/${index.stock_id}/market-prices`)
            .then((res) => {
                if (!cancelled) {
                    setPriceRows(res.data?.data || []);
                    setPriceLoaded(true);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPriceRows([]);
                    setPriceLoaded(true);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setPriceLoading(false);
                }
            });
        return () => { cancelled = true; };
    }, [expanded, index.stock_id, priceLoaded]);

    useEffect(() => {
        if (!expanded || constituentsLoaded || !index.constituents_available) {
            return;
        }
        let cancelled = false;
        setConstituentsLoading(true);
        api.get(`/indexes/${index.symbol}/constituents`)
            .then((res) => {
                if (!cancelled) {
                    setConstituents(res.data?.data?.constituents || []);
                    setConstituentsMessage(res.data?.data?.message || '');
                    setConstituentsLoaded(true);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setConstituents([]);
                    setConstituentsMessage('Failed to load constituents.');
                    setConstituentsLoaded(true);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setConstituentsLoading(false);
                }
            });
        return () => { cancelled = true; };
    }, [expanded, index.symbol, index.constituents_available, constituentsLoaded]);

    const constituentColumns = useMemo(() => [
        {
            accessorKey: 'symbol',
            header: 'Symbol',
            cell: ({ row }) => {
                const item = row.original;
                if (item.stock_id) {
                    return (
                        <Link to={`/watchlist/${encodeURIComponent(item.symbol)}`}>
                            {item.symbol}
                        </Link>
                    );
                }
                return item.symbol;
            },
        },
        {
            accessorKey: 'name',
            header: 'Name',
            cell: ({ getValue }) => getValue() || '—',
        },
    ], []);

    const filteredConstituents = useMemo(() => {
        const q = constituentSearch.trim().toLowerCase();
        if (!q) {
            return constituents;
        }
        return constituents.filter((row) => (
            row.symbol?.toLowerCase().includes(q)
            || row.name?.toLowerCase().includes(q)
        ));
    }, [constituents, constituentSearch]);

    const oneYear = index.change_percent?.['1y'];

    return (
        <div className="indices-section card mb-2">
            <button
                type="button"
                id={`${panelId}-heading`}
                className={`indices-section-toggle${expanded ? ' is-expanded' : ''}`}
                aria-expanded={expanded}
                aria-controls={panelId}
                onClick={onToggle}
            >
                <span className="indices-section-toggle-main">
                    <span className="indices-section-name">{index.name}</span>
                    <span className="indices-section-symbol">({index.symbol})</span>
                </span>
                <span className={`indices-section-1y ${pctClass(oneYear)}`}>
                    1Y {formatPctChip(oneYear)}
                </span>
                <span className="indices-section-chevron" aria-hidden="true" />
            </button>
            {expanded ? (
                <div id={panelId} className="indices-section-body card-body border-top" role="region" aria-labelledby={`${panelId}-heading`}>
                    {index.description ? (
                        <p className="indices-section-about mb-3">{index.description}</p>
                    ) : null}
                    <dl className="row small mb-3">
                        <dt className="col-sm-3 col-lg-2">Exchange</dt>
                        <dd className="col-sm-9 col-lg-10 mb-2">{index.exchange}</dd>
                        <dt className="col-sm-3 col-lg-2">Latest close</dt>
                        <dd className="col-sm-9 col-lg-10 mb-2">
                            {formatTableMoney2(index.latest_close)}
                            {index.latest_price_date ? (
                                <span className="text-muted ms-2">
                                    ({formatTransactionDateDisplay(index.latest_price_date)})
                                </span>
                            ) : null}
                        </dd>
                        <dt className="col-sm-3 col-lg-2">Price history</dt>
                        <dd className="col-sm-9 col-lg-10 mb-0">
                            {index.has_price_history ? (
                                <>
                                    {formatTransactionDateDisplay(index.price_from)}
                                    {' '}&ndash;{' '}
                                    {formatTransactionDateDisplay(index.price_to)}
                                    <span className="text-muted ms-2">({index.price_count} sessions)</span>
                                </>
                            ) : (
                                <span className="text-warning">No cached prices — sync indexes in Settings.</span>
                            )}
                        </dd>
                    </dl>

                    <IndexChangeChips changePercent={index.change_percent} />

                    {index.symbol === 'INDIAVIX' || index.tier === 'volatility' ? (
                        <IndiaVixAlertSettings />
                    ) : null}

                    <div className="mt-3">
                        <PriceVolumeChart
                            rows={priceRows}
                            loading={priceLoading}
                            title="Historical close"
                            showVolume={false}
                            showPatterns={false}
                            emptyMessage={priceLoading
                                ? 'Loading chart…'
                                : (index.has_price_history
                                    ? 'No price points returned for this index.'
                                    : 'No price history in cache.')}
                        />
                    </div>

                    {index.constituents_available ? (
                        <div className="mt-3">
                            <DataTableCard
                                title={`Constituents (${constituents.length || '…'})`}
                                headerExtra={(
                                    <input
                                        type="search"
                                        className="form-control form-control-sm"
                                        style={{ width: 220 }}
                                        placeholder="Search…"
                                        value={constituentSearch}
                                        onChange={(e) => setConstituentSearch(e.target.value)}
                                        disabled={constituentsLoading}
                                    />
                                )}
                                data={filteredConstituents}
                                columns={constituentColumns}
                                loading={constituentsLoading}
                                emptyMessage={constituentsMessage || 'No constituents returned from NSE.'}
                                storageKey={`index-constituents-${index.symbol}`}
                            />
                        </div>
                    ) : (
                        <p className="text-muted small mt-3 mb-0">
                            {index.tier === 'volatility'
                                ? 'Constituents do not apply to India VIX.'
                                : (index.exchange === 'BSE'
                                    ? 'Constituent list is not available for BSE indexes.'
                                    : 'Constituent list is not available for this index.')}
                        </p>
                    )}
                </div>
            ) : null}
        </div>
    );
}
