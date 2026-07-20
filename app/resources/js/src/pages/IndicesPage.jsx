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

export default function IndicesPage() {
    const [loading, setLoading] = useState(true);
    const [comparisonLoading, setComparisonLoading] = useState(true);
    const [indexes, setIndexes] = useState([]);
    const [comparison, setComparison] = useState(null);
    const [error, setError] = useState('');
    const [expandedKey, setExpandedKey] = useState(null);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setComparisonLoading(true);
        setError('');

        Promise.all([
            api.get('/indexes/page'),
            api.get('/indexes/comparison', { params: { months: 12 } }),
        ])
            .then(([pageRes, comparisonRes]) => {
                if (cancelled) {
                    return;
                }
                setIndexes(pageRes.data?.data?.indexes || []);
                setComparison(comparisonRes.data?.data || null);
            })
            .catch(() => {
                if (!cancelled) {
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
    }, []);

    const groups = useMemo(() => groupIndexesByExchange(indexes), [indexes]);
    const exchangeOrder = ['NSE', 'BSE'];

    if (error) {
        return <div className="alert alert-danger">{error}</div>;
    }

    return (
        <div className="indices-page">
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <div>
                    <h1 className="h3 mb-1">Indices</h1>
                    <p className="text-muted mb-0">
                        Compare broad, sector, and India VIX (expand any index below for history). VIX is omitted from the relative-performance charts.
                    </p>
                </div>
            </div>

            <IndexComparisonChart
                comparison={comparison}
                indexes={indexes}
                loading={comparisonLoading || loading}
            />

            {loading ? (
                <div className="text-muted py-4 text-center">Loading indexes…</div>
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
            )}
        </div>
    );
}
