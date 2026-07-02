import React, { useMemo, useState } from 'react';
import {
    Cell,
    Legend,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
} from 'recharts';
import {
    DataTableColumnMenu,
    DataTableView,
    useDataTableController,
} from './DataTable';
import SegmentToggle from './SegmentToggle';
import { formatInrWhole } from '../utils/tableFormat';

const ALLOCATION_VIEW_KEY = 'portfolio_dashboard_allocation_view';

const DONUT_COLORS = [
    '#0d6efd',
    '#198754',
    '#6610f2',
    '#fd7e14',
    '#20c997',
    '#dc3545',
    '#0dcaf0',
    '#ffc107',
    '#6f42c1',
    '#d63384',
    '#198754',
    '#adb5bd',
];

const chartTooltipStyle = {
    backgroundColor: 'var(--lido-chart-tooltip-bg)',
    border: '1px solid var(--lido-chart-tooltip-border)',
    borderRadius: '6px',
    color: 'var(--lido-chart-tooltip-text)',
    padding: '8px 10px',
    fontSize: '0.8125rem',
};

function loadAllocationView() {
    try {
        return localStorage.getItem(ALLOCATION_VIEW_KEY) === 'visual' ? 'visual' : 'table';
    } catch {
        return 'table';
    }
}

function saveAllocationView(mode) {
    try {
        localStorage.setItem(ALLOCATION_VIEW_KEY, mode);
    } catch {
        // Quota or private mode — ignore.
    }
}

export function buildAllocationDonutData(rows, metric, totalInvested) {
    return (rows || [])
        .map((row) => {
            const percent = metric === 'market'
                ? Number(row.allocation_market_percent || 0)
                : Number(row.allocation_invested_percent || 0);
            const value = metric === 'market'
                ? Number(row.market_value || 0)
                : (percent / 100) * Number(totalInvested || 0);

            return {
                name: row.symbol,
                value,
                percent,
            };
        })
        .filter((row) => row.value > 0)
        .sort((a, b) => b.value - a.value);
}

function AllocationDonutTooltip({ active, payload }) {
    if (!active || !payload?.length) {
        return null;
    }

    const row = payload[0].payload;
    return (
        <div style={chartTooltipStyle}>
            <div className="fw-semibold mb-1">{row.name}</div>
            <div>
                {formatInrWhole(row.value)}
                {' '}
                (
                {Math.round(row.percent)}
                %)
            </div>
        </div>
    );
}

function AllocationDonutChart({ title, data, emptyMessage }) {
    if (!data.length) {
        return (
            <div className="lido-allocation-donut">
                {title ? <div className="lido-allocation-donut__title">{title}</div> : null}
                <div className="text-muted small text-center py-4">{emptyMessage}</div>
            </div>
        );
    }

    return (
        <div className="lido-allocation-donut">
            {title ? <div className="lido-allocation-donut__title">{title}</div> : null}
            <div className="lido-allocation-donut__chart">
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie
                            data={data}
                            dataKey="value"
                            nameKey="name"
                            innerRadius="52%"
                            outerRadius="78%"
                            paddingAngle={data.length > 1 ? 1 : 0}
                            stroke="var(--lido-bg-elevated)"
                            strokeWidth={1}
                        >
                            {data.map((entry, index) => (
                                <Cell
                                    key={`${entry.name}-${index}`}
                                    fill={DONUT_COLORS[index % DONUT_COLORS.length]}
                                />
                            ))}
                        </Pie>
                        <Tooltip content={<AllocationDonutTooltip />} />
                        <Legend
                            layout="horizontal"
                            verticalAlign="bottom"
                            align="center"
                            wrapperStyle={{ fontSize: '0.75rem', paddingTop: '4px' }}
                        />
                    </PieChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

export default function DashboardAllocationCard({
    className = '',
    allocation = [],
    investedValue = 0,
    columns,
    storageKey,
    emptyMessage = 'No allocation data',
}) {
    const [viewMode, setViewMode] = useState(loadAllocationView);
    const [mobileMetric, setMobileMetric] = useState('market');

    const controller = useDataTableController({
        columns,
        data: allocation,
        storageKey,
    });

    const marketData = useMemo(
        () => buildAllocationDonutData(allocation, 'market', investedValue),
        [allocation, investedValue],
    );
    const investedData = useMemo(
        () => buildAllocationDonutData(allocation, 'invested', investedValue),
        [allocation, investedValue],
    );

    const handleViewModeChange = (mode) => {
        setViewMode(mode);
        saveAllocationView(mode);
    };

    const visualEmptyMessage = allocation.length === 0
        ? emptyMessage
        : 'No positive allocation for this measure.';

    return (
        <div className={`card ${className}`.trim()}>
            <div className="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <div className="mb-0">Allocation</div>
                <div className="d-flex align-items-center gap-2 ms-auto">
                    <SegmentToggle
                        value={viewMode}
                        onChange={handleViewModeChange}
                        ariaLabel="Allocation view"
                        compact
                        options={[
                            { value: 'table', label: 'Table' },
                            { value: 'visual', label: 'Visual' },
                        ]}
                    />
                    {viewMode === 'table' ? <DataTableColumnMenu controller={controller} /> : null}
                </div>
            </div>
            <div className="card-body">
                {viewMode === 'table' ? (
                    <DataTableView
                        controller={controller}
                        emptyMessage={emptyMessage}
                    />
                ) : (
                    <>
                        <div className="d-none d-lg-block">
                            <div className="row g-3">
                                <div className="col-lg-6">
                                    <AllocationDonutChart
                                        title="Market value"
                                        data={marketData}
                                        emptyMessage={visualEmptyMessage}
                                    />
                                </div>
                                <div className="col-lg-6">
                                    <AllocationDonutChart
                                        title="Invested"
                                        data={investedData}
                                        emptyMessage={visualEmptyMessage}
                                    />
                                </div>
                            </div>
                        </div>
                        <div className="d-lg-none">
                            <div className="d-flex justify-content-center mb-3">
                                <SegmentToggle
                                    value={mobileMetric}
                                    onChange={setMobileMetric}
                                    ariaLabel="Allocation measure"
                                    compact
                                    options={[
                                        { value: 'market', label: 'Market value' },
                                        { value: 'invested', label: 'Invested' },
                                    ]}
                                />
                            </div>
                            <AllocationDonutChart
                                title=""
                                data={mobileMetric === 'market' ? marketData : investedData}
                                emptyMessage={visualEmptyMessage}
                            />
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
