import React, { useEffect, useMemo, useState } from 'react';
import api from '../api';
import IndexComparisonChart from '../components/indices/IndexComparisonChart';
import IndexSection from '../components/indices/IndexSection';
import { groupIndexesByExchange, groupIndexesByTier } from '../utils/indexChartHelpers';

function IndexTierList({ exchange, indexes, expandedKey, setExpandedKey }) {
    const tiers = useMemo(() => groupIndexesByTier(indexes), [indexes]);
    const blocks = [
        { key: 'broad', title: 'Broad market', items: tiers.broad },
        { key: 'sector', title: 'Sector', items: tiers.sector },
        { key: 'volatility', title: 'Volatility', items: tiers.volatility },
    ].filter((block) => block.items.length > 0);

    return (
        <div className="mb-4">
            <h2 className="h5 mb-2">{exchange}</h2>
            {blocks.map((block) => (
                <div key={`${exchange}-${block.key}`} className="mb-3">
                    {blocks.length > 1 ? (
                        <h3 className="h6 text-muted mb-2">{block.title}</h3>
                    ) : null}
                    <div className="indices-section-list">
                        {block.items.map((index) => {
                            const panelKey = `${exchange}-${index.symbol}`;
                            return (
                                <IndexSection
                                    key={panelKey}
                                    index={index}
                                    panelId={`index-panel-${panelKey}`}
                                    expanded={expandedKey === panelKey}
                                    onToggle={() => setExpandedKey((prev) => (prev === panelKey ? null : panelKey))}
                                />
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

function isIndexOverview(payload) {
    return Boolean(
        payload
        && Array.isArray(payload.indexes)
        && payload.indexes.every((index) => (
            index
            && typeof index.symbol === 'string'
            && index.symbol.trim() !== ''
            && typeof index.name === 'string'
            && typeof index.exchange === 'string'
            && Number.isFinite(Number(index.stock_id))
            && Number(index.stock_id) > 0
            && typeof index.has_price_history === 'boolean'
            && typeof index.constituents_available === 'boolean'
        ))
    );
}

function isIndexComparison(payload) {
    return Boolean(
        payload
        && Array.isArray(payload.series)
        && payload.series.every((series) => (
            series
            && typeof series.symbol === 'string'
            && typeof series.name === 'string'
            && Array.isArray(series.points)
            && series.points.every((point) => (
                point
                && typeof point.date === 'string'
                && point.date.trim() !== ''
                && typeof point.gain_percent === 'number'
                && Number.isFinite(point.gain_percent)
            ))
        ))
    );
}

export default function IndicesPage() {
    const [loading, setLoading] = useState(true);
    const [comparisonLoading, setComparisonLoading] = useState(true);
    const [indexes, setIndexes] = useState([]);
    const [comparison, setComparison] = useState(null);
    const [overviewReady, setOverviewReady] = useState(false);
    const [comparisonReady, setComparisonReady] = useState(false);
    const [error, setError] = useState('');
    const [expandedKey, setExpandedKey] = useState(null);
    const [retryKey, setRetryKey] = useState(0);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setComparisonLoading(true);
        setError('');
        setOverviewReady(false);
        setComparisonReady(false);

        Promise.allSettled([
            api.get('/indexes/page'),
            api.get('/indexes/comparison', { params: { months: 12 } }),
        ])
            .then(([pageResult, comparisonResult]) => {
                if (cancelled) {
                    return;
                }
                const overview = pageResult.status === 'fulfilled' ? pageResult.value.data?.data : null;
                const comparisonData = comparisonResult.status === 'fulfilled' ? comparisonResult.value.data?.data : null;
                const overviewValid = isIndexOverview(overview);
                const comparisonValid = isIndexComparison(comparisonData);
                if (overviewValid) {
                    setIndexes(overview.indexes);
                }
                if (comparisonValid) {
                    setComparison(comparisonData);
                }
                setOverviewReady(overviewValid);
                setComparisonReady(comparisonValid);
                if (!overviewValid || !comparisonValid) {
                    setError('Failed to load index data.');
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                    setComparisonLoading(false);
                }
            });

        return () => { cancelled = true; };
    }, [retryKey]);

    const groups = useMemo(() => groupIndexesByExchange(indexes), [indexes]);
    const exchangeOrder = ['NSE', 'BSE'];

    if (error && !overviewReady) {
        return <div className="alert alert-danger" role="alert"><p>{error}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={loading} onClick={() => setRetryKey(key => key + 1)}>Retry index data</button></div>;
    }

    return (
        <div className="indices-page">
            {error ? <div className="alert alert-danger" role="alert"><p>{error}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={loading} onClick={() => setRetryKey(key => key + 1)}>Retry index data</button></div> : null}
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Indices</h1>
                    <p className="text-muted mb-0">
                        Compare broad, sector, and India VIX (expand any index below for history). VIX is omitted from the relative-performance charts.
                    </p>
                </div>
            </div>

            {comparisonReady ? (
                <IndexComparisonChart
                    comparison={comparison}
                    indexes={indexes}
                    loading={comparisonLoading || loading}
                />
            ) : (
                <div className="text-muted small py-4 text-center" role="status">Relative performance comparison is unavailable.</div>
            )}

            {loading ? (
                <div className="text-muted py-4 text-center">Loading indexes…</div>
            ) : (
                indexes.length === 0 ? (
                    <div className="text-muted py-4" role="status">No index records are available.</div>
                ) : (
                    exchangeOrder.filter((exchange) => groups[exchange]?.length).map((exchange) => (
                        <IndexTierList
                            key={exchange}
                            exchange={exchange}
                            indexes={groups[exchange]}
                            expandedKey={expandedKey}
                            setExpandedKey={setExpandedKey}
                        />
                    ))
                )
            )}
        </div>
    );
}
